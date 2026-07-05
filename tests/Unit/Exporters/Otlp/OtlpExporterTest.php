<?php

declare(strict_types=1);

use Cbox\Telemetry\Events\TelemetryEvent;
use Cbox\Telemetry\Exporters\Otlp\OtlpExporter;
use Cbox\Telemetry\Exporters\Otlp\OtlpSerializer;
use Cbox\Telemetry\Exporters\Otlp\OtlpTransport;
use Cbox\Telemetry\Metrics\MetricDefinition;
use Cbox\Telemetry\Metrics\MetricFamily;
use Cbox\Telemetry\Metrics\MetricType;
use Cbox\Telemetry\Metrics\Sample;
use Cbox\Telemetry\Support\ExportResult;
use Cbox\Telemetry\Support\TelemetryBatch;
use Cbox\Telemetry\Tracing\Tracer;

beforeEach(fn () => OtlpExporter::resetCircuit());

final class FakeTransport extends OtlpTransport
{
    /** @var array<string, array<string, mixed>> */
    public array $posts = [];

    /** @var array<string, ExportResult> */
    public array $responses = [];

    public function __construct()
    {
        parent::__construct('http://fake:4318');
    }

    public function post(string $path, array $payload): ExportResult
    {
        $this->posts[$path] = $payload;

        return $this->responses[$path] ?? ExportResult::ok();
    }
}

function otlpBatch(): TelemetryBatch
{
    $tracer = new Tracer;
    $tracer->span('work', fn () => null);

    return new TelemetryBatch(
        resource: ['service.name' => 'testapp'],
        spans: $tracer->drain(),
        metrics: [new MetricFamily(
            new MetricDefinition('orders.created', MetricType::Counter),
            [new Sample([], 1.0)],
        )],
        events: [new TelemetryEvent('deploy.finished', 1_700_000_000_000_000_000)],
    );
}

it('posts each signal to its own OTLP path', function () {
    $transport = new FakeTransport;
    $exporter = new OtlpExporter($transport, new OtlpSerializer(['service.name' => 'testapp']));

    $result = $exporter->export(otlpBatch());

    expect($result->success)->toBeTrue()
        ->and(array_keys($transport->posts))->toBe(['/v1/traces', '/v1/metrics', '/v1/logs']);
});

it('skips paths for empty signals', function () {
    $transport = new FakeTransport;
    $exporter = new OtlpExporter($transport, new OtlpSerializer([]));

    $exporter->export(new TelemetryBatch(events: [new TelemetryEvent('only.events', 1)]));

    expect(array_keys($transport->posts))->toBe(['/v1/logs']);
});

it('surfaces the first failure and opens the circuit', function () {
    $transport = new FakeTransport;
    $transport->responses['/v1/metrics'] = ExportResult::retryable('HTTP 503', retryAfterSeconds: 30);

    $exporter = new OtlpExporter($transport, new OtlpSerializer([]));

    $result = $exporter->export(otlpBatch());

    expect($result->success)->toBeFalse()
        ->and($result->retryable)->toBeTrue()
        ->and($result->retryAfterSeconds)->toBe(30);

    // Circuit is now open: the next export never touches the transport.
    $posts = count($transport->posts);
    $second = $exporter->export(otlpBatch());

    expect(count($transport->posts))->toBe($posts)
        ->and($second->retryable)->toBeTrue()
        ->and($second->reason)->toContain('circuit open');
});

it('surfaces partial rejections when everything else succeeded', function () {
    $transport = new FakeTransport;
    $transport->responses['/v1/traces'] = ExportResult::partial(2, 'invalid spans');

    $exporter = new OtlpExporter($transport, new OtlpSerializer([]));

    $result = $exporter->export(otlpBatch());

    expect($result->success)->toBeTrue()
        ->and($result->rejected)->toBe(2);
});
