<?php

declare(strict_types=1);

use Cbox\Telemetry\Events\TelemetryEvent;
use Cbox\Telemetry\Exceptions\GaugeShapeConflict;
use Cbox\Telemetry\Exporters\Otlp\OtlpSerializer;
use Cbox\Telemetry\Exporters\Prometheus\PrometheusRenderer;
use Cbox\Telemetry\Metrics\MetricDefinition;
use Cbox\Telemetry\Metrics\MetricFamily;
use Cbox\Telemetry\Metrics\MetricType;
use Cbox\Telemetry\Metrics\Registry;
use Cbox\Telemetry\Metrics\Sample;
use Cbox\Telemetry\Metrics\Stores\ArrayMetricStore;
use Cbox\Telemetry\Support\TraceParent;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\Tracer;

it('rejects mixing push and observable gauges under one name', function () {
    $registry = new Registry(new ArrayMetricStore, []);

    $registry->gauge('queue.depth');
    $registry->gauge('queue.depth', fn () => 1.0);
})->throws(GaugeShapeConflict::class);

it('rejects the reverse gauge shape mix too', function () {
    $registry = new Registry(new ArrayMetricStore, []);

    $registry->gauge('queue.depth', fn () => 1.0);
    $registry->gauge('queue.depth');
})->throws(GaugeShapeConflict::class);

it('caps the event buffer and force-flushes', function () {
    $manager = new TelemetryManager(
        enabled: true,
        registry: new Registry(new ArrayMetricStore, []),
        tracer: new Tracer,
        maxBufferedEvents: 10,
    );

    $collector = new CollectingExporter;
    $manager->addExporter($collector);

    foreach (range(1, 25) as $i) {
        $manager->event("event.{$i}");
    }

    // Two force-flushes at 10 and 20; 5 remain buffered.
    expect($collector->batches())->toHaveCount(2)
        ->and(array_sum(array_map(fn ($batch) => count($batch->events), $collector->batches())))->toBe(20);
});

it('deduplicates same-name families instead of failing the scrape', function () {
    $definition = new MetricDefinition('queue.depth', MetricType::Gauge);

    $output = (new PrometheusRenderer)->render([
        new MetricFamily($definition, [new Sample(['queue' => 'a'], 1.0)]),
        new MetricFamily($definition, [new Sample(['queue' => 'b'], 2.0)]),
    ]);

    expect(substr_count($output, '# TYPE queue_depth gauge'))->toBe(1)
        ->and($output)->toContain('queue_depth{queue="a"} 1')
        ->toContain('queue_depth{queue="b"} 2');
});

it('renders numeric label keys without crashing', function () {
    $output = (new PrometheusRenderer)->render([
        new MetricFamily(
            new MetricDefinition('odd.labels', MetricType::Counter),
            [new Sample(['0' => 'zero'], 1.0)],
        ),
    ]);

    expect($output)->toContain('odd_labels_total{_0="zero"} 1');
});

it('re-decides sampling locally when incoming sampling is untrusted', function () {
    $tracer = new Tracer(sampleRate: 0.0);

    $tracer->continueFrom(new TraceParent(
        traceId: '0af7651916cd43dd8448eb211c80319c',
        spanId: 'b7ad6b7169203331',
        sampled: true, // the caller demands sampling…
    ), trustSampling: false);

    $span = $tracer->startSpan('work');
    $span->end();

    // …but our local rate is 0, so nothing is exported — while ids are
    // still continued for correlation.
    expect($span->traceId)->toBe('0af7651916cd43dd8448eb211c80319c')
        ->and($span->parentSpanId)->toBe('b7ad6b7169203331')
        ->and($tracer->drain())->toBeEmpty();
});

it('never registers a provider twice when more are added later', function () {
    $manager = new TelemetryManager(
        enabled: true,
        registry: new Registry(new ArrayMetricStore, []),
        tracer: new Tracer,
    );

    $runs = ['a' => 0, 'b' => 0];

    $manager->contributes('a', function () use (&$runs) {
        $runs['a']++;
    });

    $manager->collect();

    $manager->contributes('b', function () use (&$runs) {
        $runs['b']++;
    });

    $manager->collect();
    $manager->collect();

    expect($runs)->toBe(['a' => 1, 'b' => 1]);
});

it('exposes the cumulative start timestamp through the store', function () {
    $store = new ArrayMetricStore;

    $store->incrementCounter(new MetricDefinition('orders.created', MetricType::Counter), [], 1);

    $family = $store->collect()[0];

    expect($family->startUnixNano)->toBeInt()
        ->and($family->startUnixNano)->toBeGreaterThan(1_500_000_000 * 1_000_000_000);
});

it('serializes NAN and INF without dropping the batch', function () {
    $serializer = new OtlpSerializer([]);

    $payload = $serializer->metrics([
        new MetricFamily(
            new MetricDefinition('poisoned.gauge', MetricType::Gauge),
            [new Sample([], NAN)],
        ),
    ]);

    expect(json_encode($payload))->toBeString();

    $logs = $serializer->logs([
        new TelemetryEvent('e', 1, ['bad' => INF]),
    ]);

    expect(json_encode($logs))->toBeString();
});
