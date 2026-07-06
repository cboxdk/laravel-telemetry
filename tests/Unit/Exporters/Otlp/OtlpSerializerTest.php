<?php

declare(strict_types=1);

use Cbox\Telemetry\Events\TelemetryEvent;
use Cbox\Telemetry\Exporters\Otlp\OtlpSerializer;
use Cbox\Telemetry\Metrics\HistogramSample;
use Cbox\Telemetry\Metrics\MetricDefinition;
use Cbox\Telemetry\Metrics\MetricFamily;
use Cbox\Telemetry\Metrics\MetricType;
use Cbox\Telemetry\Metrics\Sample;
use Cbox\Telemetry\Tracing\SpanLink;
use Cbox\Telemetry\Tracing\Tracer;

function serializer(): OtlpSerializer
{
    return new OtlpSerializer(['service.name' => 'testapp']);
}

it('serializes spans with hex ids, string nanos and integer enums', function () {
    $tracer = new Tracer;

    $tracer->span('parent', fn () => $tracer->span('child', fn () => null));

    [$child, $parent] = $tracer->drain();

    $payload = serializer()->traces([$parent, $child]);

    $spans = $payload['resourceSpans'][0]['scopeSpans'][0]['spans'];

    expect($spans[0]['traceId'])->toMatch('/^[0-9a-f]{32}$/')
        ->and($spans[0]['spanId'])->toMatch('/^[0-9a-f]{16}$/')
        ->and($spans[0]['kind'])->toBeInt()
        ->and($spans[0]['startTimeUnixNano'])->toBeString()
        ->and($spans[0]['status']['code'])->toBe(1)
        ->and($spans[1]['parentSpanId'])->toBe($spans[0]['spanId']);

    // The whole payload must be JSON-encodable.
    expect(json_encode($payload))->toBeString();
});

it('serializes span links with hex ids and attributes', function () {
    $tracer = new Tracer;

    $span = $tracer->startSpan('retry', links: [new SpanLink('a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5', 'a0b1c2d3e4f5a6b7', ['queue.retry' => true])]);
    $span->end();

    $payload = serializer()->traces($tracer->drain());
    $data = $payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0];

    expect($data['links'][0]['traceId'])->toBe('a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5')
        ->and($data['links'][0]['spanId'])->toBe('a0b1c2d3e4f5a6b7')
        ->and($data['links'][0]['attributes'][0])->toBe(['key' => 'queue.retry', 'value' => ['boolValue' => true]]);
});

it('omits the links key entirely for a span with no links', function () {
    $tracer = new Tracer;

    $span = $tracer->startSpan('no-links');
    $span->end();

    $payload = serializer()->traces($tracer->drain());
    $data = $payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0];

    expect($data)->not->toHaveKey('links');
});

it('serializes resource attributes as OTLP keyvalues', function () {
    $payload = serializer()->traces([]);

    expect($payload['resourceSpans'][0]['resource']['attributes'][0])
        ->toBe(['key' => 'service.name', 'value' => ['stringValue' => 'testapp']]);
});

it('serializes counters as cumulative monotonic sums', function () {
    $payload = serializer()->metrics([
        new MetricFamily(
            new MetricDefinition('orders.created', MetricType::Counter, 'Orders', ''),
            [new Sample(['tenant' => 'a'], 3.0)],
        ),
    ]);

    $metric = $payload['resourceMetrics'][0]['scopeMetrics'][0]['metrics'][0];

    expect($metric['name'])->toBe('orders.created')
        ->and($metric['sum']['aggregationTemporality'])->toBe(2)
        ->and($metric['sum']['isMonotonic'])->toBeTrue()
        ->and($metric['sum']['dataPoints'][0]['asDouble'])->toBe(3.0)
        ->and($metric['sum']['dataPoints'][0]['attributes'][0]['key'])->toBe('tenant');
});

it('serializes histograms with string uint64 counts and explicit bounds', function () {
    $payload = serializer()->metrics([
        new MetricFamily(
            new MetricDefinition('req.duration', MetricType::Histogram, unit: 'ms', buckets: [10.0, 100.0]),
            [new HistogramSample([], [10.0, 100.0], [2, 1, 1], 5065.0, 4)],
        ),
    ]);

    $point = $payload['resourceMetrics'][0]['scopeMetrics'][0]['metrics'][0]['histogram']['dataPoints'][0];

    expect($point['count'])->toBe('4')
        ->and($point['bucketCounts'])->toBe(['2', '1', '1'])
        ->and($point['explicitBounds'])->toBe([10.0, 100.0])
        ->and($point['sum'])->toBe(5065.0);
});

it('serializes events as trace-correlated log records', function () {
    $payload = serializer()->logs([
        new TelemetryEvent(
            name: 'autoscale.decision',
            timeUnixNano: 1_700_000_000_000_000_000,
            attributes: ['workers' => 5],
            traceId: '0af7651916cd43dd8448eb211c80319c',
            spanId: 'b7ad6b7169203331',
        ),
    ]);

    $record = $payload['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];

    expect($record['body'])->toBe(['stringValue' => 'autoscale.decision'])
        ->and($record['timeUnixNano'])->toBe('1700000000000000000')
        ->and($record['traceId'])->toBe('0af7651916cd43dd8448eb211c80319c')
        ->and($record['attributes'][0])->toBe(['key' => 'workers', 'value' => ['intValue' => '5']]);
});
