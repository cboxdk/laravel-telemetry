<?php

declare(strict_types=1);

use Cbox\Telemetry\Events\TelemetryEvent;
use Cbox\Telemetry\Exporters\Otlp\OtlpExporter;
use Cbox\Telemetry\Exporters\Otlp\OtlpSerializer;
use Cbox\Telemetry\Exporters\Otlp\OtlpTransport;
use Cbox\Telemetry\Support\ExportResult;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\Support\ProcessMemory;
use Cbox\Telemetry\Support\SharedState;
use Cbox\Telemetry\Support\TelemetryBatch;
use Cbox\Telemetry\Tests\Doubles\PoolMemory;
use Cbox\Telemetry\Tracing\Tracer;

/**
 * Under PHP-FPM every request starts with a fresh engine state, so a
 * cooldown held in a static property lasts exactly one request. That
 * turns a circuit breaker into a no-op and a report throttle into "one
 * report per request" — both of them at the moment a backend is down
 * and the application can least afford it.
 *
 * These tests simulate the boundary: the pool's shared memory stands
 * still while the process statics are thrown away.
 */
final class OutageTransport extends OtlpTransport
{
    public int $posts = 0;

    public function __construct()
    {
        parent::__construct('http://collector:4318');
    }

    public function post(string $path, array $payload): ExportResult
    {
        $this->posts++;

        return ExportResult::unreachable('connection refused');
    }
}

function outageBatch(): TelemetryBatch
{
    $tracer = new Tracer;
    $tracer->span('work', fn () => null);

    return new TelemetryBatch(
        resource: ['service.name' => 'shop'],
        spans: $tracer->drain(),
        events: [new TelemetryEvent('order.placed', 1)],
    );
}

/**
 * Everything a PHP-FPM request loses on its way out.
 *
 * Every static in the classes under test goes back to its declared
 * default — anything less would let the old, static-held cooldown pass
 * these tests. Two things a real request does keep: the handler, which
 * the service provider registers again on boot, and the shared memory
 * segment, which lives outside the process and is what is being
 * tested.
 */
function nextRequest(): void
{
    foreach ([FailSafe::class, OtlpExporter::class, SharedState::class] as $class) {
        $reflection = new ReflectionClass($class);
        $defaults = $reflection->getDefaultProperties();

        foreach ($reflection->getProperties(ReflectionProperty::IS_STATIC) as $property) {
            if (in_array($property->getName(), ['handler', 'memory'], true)) {
                continue;
            }

            $property->setValue(null, $defaults[$property->getName()] ?? null);
        }
    }
}

beforeEach(function () {
    $this->pool = new PoolMemory;
    SharedState::use($this->pool);
});

afterEach(function () {
    SharedState::use(null);
    FailSafe::handleExceptionsUsing(null);
});

it('keeps the circuit open across a request boundary', function () {
    $transport = new OutageTransport;
    $exporter = new OtlpExporter($transport, new OtlpSerializer([]));

    $exporter->export(outageBatch());
    expect($transport->posts)->toBe(1);

    // Nine more requests hit the same dead collector. Every one of them
    // must cost nothing: no connect, no timeout, no worker held.
    for ($i = 0; $i < 9; $i++) {
        nextRequest();

        $result = $exporter->export(outageBatch());

        expect($result->success)->toBeFalse();
    }

    expect($transport->posts)->toBe(1)
        ->and(OtlpExporter::circuitOpen())->toBeTrue();
});

it('stops at the first UNREACHABLE signal instead of timing out once per signal', function () {
    // A batch carries traces and logs to the same collector. Posting
    // the second after the first proved it unreachable pays the connect
    // timeout twice for an answer already known.
    $transport = new OutageTransport;
    $exporter = new OtlpExporter($transport, new OtlpSerializer([]));

    $exporter->export(outageBatch());

    expect($transport->posts)->toBe(1);
});

it('still tries the rest of the batch when the collector answered', function () {
    // A 503 is not an unreachable collector: it came back from a
    // server that answered promptly, the next signal may well be
    // accepted, and skipping it drops data the manager has already
    // handed over and cleared. Only a connect failure justifies
    // stopping.
    $transport = new class extends OtlpTransport
    {
        public int $posts = 0;

        public function __construct()
        {
            parent::__construct('http://collector:4318');
        }

        public function post(string $path, array $payload): ExportResult
        {
            $this->posts++;

            return $path === '/v1/traces'
                ? ExportResult::retryable('HTTP 503: overloaded')
                : ExportResult::ok();
        }
    };

    (new OtlpExporter($transport, new OtlpSerializer([])))->export(outageBatch());

    expect($transport->posts)->toBe(2);
});

it('falls back to per-process state when no shared memory exists', function () {
    // The long-lived processes where APCu is usually off — a queue
    // worker, Octane, the scheduler — keep the old behaviour, which is
    // correct for them because their statics do survive.
    SharedState::use(new ProcessMemory);

    $transport = new OutageTransport;
    $exporter = new OtlpExporter($transport, new OtlpSerializer([]));

    $exporter->export(outageBatch());
    $exporter->export(outageBatch());

    expect($transport->posts)->toBe(1)
        ->and(SharedState::isShared())->toBeFalse();
});

it('throttles reports across a request boundary', function () {
    $reported = [];
    FailSafe::handleExceptionsUsing(function (Throwable $e) use (&$reported): void {
        $reported[] = $e->getMessage();
    });

    $fail = static fn () => throw new RuntimeException('the metric store is refusing writes');

    for ($request = 0; $request < 50; $request++) {
        nextRequest();

        for ($i = 0; $i < 20; $i++) {
            FailSafe::guard($fail);
        }
    }

    // A thousand failures across fifty requests: one report, not fifty.
    expect($reported)->toHaveCount(1);
});

it('still reports a second, unrelated failure during an outage', function () {
    $reported = [];
    FailSafe::handleExceptionsUsing(function (Throwable $e) use (&$reported): void {
        $reported[] = $e->getMessage();
    });

    FailSafe::guard(static fn () => throw new RuntimeException('store refusing writes'));
    nextRequest();
    FailSafe::guard(static fn () => throw new LogicException('something else entirely'));

    expect($reported)->toHaveCount(2);
});

it('lets a deadline expire', function () {
    SharedState::remember('probe', time() + 1);
    expect(SharedState::deadline('probe'))->toBeGreaterThan(time());

    SharedState::remember('probe', time() - 1);
    expect(SharedState::deadline('probe'))->toBe(0);
});

it('does not let its key bookkeeping grow without bound', function () {
    $tracked = new ReflectionProperty(SharedState::class, 'keys');
    $cap = (new ReflectionClassConstant(SharedState::class, 'MAX_KEYS'))->getValue();

    for ($i = 0; $i < $cap + 10; $i++) {
        SharedState::remember('synthetic-'.$i, time() + 60);
    }

    expect(count($tracked->getValue()))->toBeLessThanOrEqual($cap);
});
