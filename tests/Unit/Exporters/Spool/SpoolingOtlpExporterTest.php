<?php

declare(strict_types=1);

use Cbox\Telemetry\Events\TelemetryEvent;
use Cbox\Telemetry\Exporters\Otlp\OtlpExporter;
use Cbox\Telemetry\Exporters\Otlp\OtlpSerializer;
use Cbox\Telemetry\Exporters\Otlp\OtlpTransport;
use Cbox\Telemetry\Exporters\Spool\Spool;
use Cbox\Telemetry\Exporters\Spool\SpoolingOtlpExporter;
use Cbox\Telemetry\Metrics\MetricDefinition;
use Cbox\Telemetry\Metrics\MetricFamily;
use Cbox\Telemetry\Metrics\MetricType;
use Cbox\Telemetry\Metrics\Sample;
use Cbox\Telemetry\Support\ExportResult;
use Cbox\Telemetry\Support\SharedState;
use Cbox\Telemetry\Support\TelemetryBatch;
use Cbox\Telemetry\Tracing\Tracer;

beforeEach(function () {
    SpoolingOtlpExporter::resetCircuit();
    OtlpExporter::resetCircuit();
});

// These tests open the breaker on purpose. With APCu on in the CLI (as in CI) the state outlives
// the test, and an open circuit would make the next file's spool tests silently skip the spool.
afterEach(fn () => SpoolingOtlpExporter::resetCircuit());

/** A spool that records pushes and can be told to fail like an unreachable Redis. */
final class BreakerSpool implements Spool
{
    public int $pushes = 0;

    public bool $failing = false;

    public function push(array $entry): void
    {
        $this->pushMany([$entry]);
    }

    public function pushMany(array $entries): void
    {
        $this->pushes++;

        if ($this->failing) {
            throw new RuntimeException('Connection timed out [tcp://telemetry.test:6379]');
        }
    }

    public function pop(int $count): array
    {
        return [];
    }

    public function requeue(array $entries): void {}

    public function size(): int
    {
        return 0;
    }
}

final class BreakerTransport extends OtlpTransport
{
    /** @var list<string> */
    public array $paths = [];

    public function __construct()
    {
        parent::__construct('http://fake:4318');
    }

    public function post(string $path, array $payload): ExportResult
    {
        $this->paths[] = $path;

        return ExportResult::ok();
    }
}

function spoolBreakerBatch(bool $withMetrics = false): TelemetryBatch
{
    $tracer = new Tracer;
    $tracer->span('work', fn () => null);

    return new TelemetryBatch(
        resource: ['service.name' => 'testapp'],
        spans: $tracer->drain(),
        metrics: $withMetrics ? [new MetricFamily(
            new MetricDefinition('orders.created', MetricType::Counter),
            [new Sample([], 1.0)],
        )] : [],
        events: [new TelemetryEvent('deploy.finished', 1_700_000_000_000_000_000)],
    );
}

function spoolingExporter(BreakerSpool $spool, ?BreakerTransport $transport = null): SpoolingOtlpExporter
{
    return new SpoolingOtlpExporter(
        new OtlpExporter($transport ?? new BreakerTransport, new OtlpSerializer([])),
        new OtlpSerializer([]),
        $spool,
    );
}

it('pushes spans and events in one call while the spool is healthy', function () {
    $spool = new BreakerSpool;

    $result = spoolingExporter($spool)->export(spoolBreakerBatch());

    expect($result->success)->toBeTrue()
        ->and($spool->pushes)->toBe(1)
        ->and(SpoolingOtlpExporter::circuitOpen())->toBeFalse();
});

it('opens the circuit on a failed push and still surfaces the failure', function () {
    $spool = new BreakerSpool;
    $spool->failing = true;

    expect(fn () => spoolingExporter($spool)->export(spoolBreakerBatch()))
        ->toThrow(RuntimeException::class, 'Connection timed out');

    expect(SpoolingOtlpExporter::circuitOpen())->toBeTrue();
});

it('skips the spool entirely while the circuit is open', function () {
    $spool = new BreakerSpool;
    $spool->failing = true;
    $exporter = spoolingExporter($spool);

    try {
        $exporter->export(spoolBreakerBatch());
    } catch (RuntimeException) {
    }

    // The point of the breaker: a dead spool costs one push per window,
    // not one timeout per request.
    $second = $exporter->export(spoolBreakerBatch());
    $third = $exporter->export(spoolBreakerBatch());

    expect($spool->pushes)->toBe(1)
        ->and($second->success)->toBeFalse()
        ->and($second->retryable)->toBeTrue()
        ->and($second->reason)->toContain('spool circuit open')
        ->and($third->reason)->toContain('spool circuit open');
});

it('tries the spool again once the cooldown has passed', function () {
    $spool = new BreakerSpool;
    $spool->failing = true;
    $exporter = spoolingExporter($spool);

    try {
        $exporter->export(spoolBreakerBatch());
    } catch (RuntimeException) {
    }

    // Wind the deadline back rather than sleeping out the cooldown.
    SharedState::remember('spool:circuit', time() - 1);
    $spool->failing = false;

    $result = $exporter->export(spoolBreakerBatch());

    expect($result->success)->toBeTrue()
        ->and($spool->pushes)->toBe(2)
        ->and(SpoolingOtlpExporter::circuitOpen())->toBeFalse();
});

it('keeps exporting metrics while the spool circuit is open', function () {
    $spool = new BreakerSpool;
    $spool->failing = true;
    $transport = new BreakerTransport;
    $exporter = spoolingExporter($spool, $transport);

    try {
        $exporter->export(spoolBreakerBatch());
    } catch (RuntimeException) {
    }

    $result = $exporter->export(spoolBreakerBatch(withMetrics: true));

    // Metrics go to the collector, not the spool, so a dead spool must
    // not take them down with it; the skipped spans are still reported.
    expect($transport->paths)->toBe(['/v1/metrics'])
        ->and($spool->pushes)->toBe(1)
        ->and($result->retryable)->toBeTrue()
        ->and($result->reason)->toContain('spool circuit open');
});
