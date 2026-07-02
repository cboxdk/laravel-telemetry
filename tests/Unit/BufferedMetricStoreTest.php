<?php

declare(strict_types=1);

use Cbox\Telemetry\Contracts\MetricStore;
use Cbox\Telemetry\Metrics\MetricDefinition;
use Cbox\Telemetry\Metrics\MetricType;
use Cbox\Telemetry\Metrics\Registry;
use Cbox\Telemetry\Metrics\Stores\ArrayMetricStore;
use Cbox\Telemetry\Metrics\Stores\BufferedMetricStore;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\Tracer;

/**
 * Counts every write that reaches the inner store.
 */
final class CountingStore implements MetricStore
{
    public int $writes = 0;

    public function __construct(private readonly ArrayMetricStore $inner = new ArrayMetricStore) {}

    public function incrementCounter(MetricDefinition $definition, array $labels, float $by): void
    {
        $this->writes++;
        $this->inner->incrementCounter($definition, $labels, $by);
    }

    public function setGauge(MetricDefinition $definition, array $labels, float $value): void
    {
        $this->writes++;
        $this->inner->setGauge($definition, $labels, $value);
    }

    public function addGauge(MetricDefinition $definition, array $labels, float $delta): void
    {
        $this->writes++;
        $this->inner->addGauge($definition, $labels, $delta);
    }

    public function recordHistogram(MetricDefinition $definition, array $labels, float $value): void
    {
        $this->writes++;
        $this->inner->recordHistogram($definition, $labels, $value);
    }

    public function mergeHistogram(MetricDefinition $definition, array $labels, array $bucketCounts, float $sum, int $count): void
    {
        $this->writes++;
        $this->inner->mergeHistogram($definition, $labels, $bucketCounts, $sum, $count);
    }

    public function collect(): array
    {
        return $this->inner->collect();
    }

    public function wipe(): void
    {
        $this->inner->wipe();
    }
}

function counterDefinition(): MetricDefinition
{
    return new MetricDefinition('orders.created', MetricType::Counter);
}

it('aggregates repeated counter increments into one inner write', function () {
    $counting = new CountingStore;
    $buffered = new BufferedMetricStore($counting);

    foreach (range(1, 100) as $i) {
        $buffered->incrementCounter(counterDefinition(), ['tenant' => 'a'], 1);
    }

    expect($counting->writes)->toBe(0);

    $buffered->flushBuffer();

    expect($counting->writes)->toBe(1)
        ->and($counting->collect()[0]->samples[0]->value)->toBe(100.0);
});

it('aggregates histogram observations into one merged inner write', function () {
    $counting = new CountingStore;
    $buffered = new BufferedMetricStore($counting);

    $definition = new MetricDefinition('req.duration', MetricType::Histogram, buckets: [10.0, 100.0]);

    foreach ([5, 8, 50, 5000] as $value) {
        $buffered->recordHistogram($definition, [], (float) $value);
    }

    $buffered->flushBuffer();

    $sample = $counting->collect()[0]->samples[0];

    expect($counting->writes)->toBe(1)
        ->and($sample->bucketCounts)->toBe([2, 1, 1])
        ->and($sample->count)->toBe(4)
        ->and($sample->sum)->toBe(5063.0);
});

it('folds gauge sets and deltas in order', function () {
    $counting = new CountingStore;
    $buffered = new BufferedMetricStore($counting);

    $definition = new MetricDefinition('jobs.in_flight', MetricType::Gauge);

    $buffered->addGauge($definition, [], 3);       // pending add +3
    $buffered->setGauge($definition, [], 10);      // set wins over the add
    $buffered->addGauge($definition, [], -2);      // folds into the set

    $buffered->flushBuffer();

    expect($counting->writes)->toBe(1)
        ->and($counting->collect()[0]->samples[0]->value)->toBe(8.0);
});

it('keeps pure deltas as an atomic add for cross-process correctness', function () {
    $counting = new CountingStore;
    $buffered = new BufferedMetricStore($counting);

    $definition = new MetricDefinition('jobs.in_flight', MetricType::Gauge);

    $counting->setGauge($definition, [], 100.0); // another process wrote 100

    $buffered->addGauge($definition, [], 5);
    $buffered->addGauge($definition, [], -2);
    $buffered->flushBuffer();

    expect($counting->collect()[0]->samples[0]->value)->toBe(103.0);
});

it('force-flushes at the pending cap', function () {
    $counting = new CountingStore;
    $buffered = new BufferedMetricStore($counting, maxPending: 10);

    foreach (range(1, 10) as $i) {
        $buffered->incrementCounter(counterDefinition(), [], 1);
    }

    // Cap reached: flushed without an explicit flushBuffer() call.
    expect($counting->writes)->toBe(1);
});

it('flushes before collect so scrapes see everything', function () {
    $buffered = new BufferedMetricStore(new CountingStore);

    $buffered->incrementCounter(counterDefinition(), [], 7);

    expect($buffered->collect()[0]->samples[0]->value)->toBe(7.0);
});

it('flushes the buffer on manager flush even without spans or events', function () {
    $counting = new CountingStore;
    $buffered = new BufferedMetricStore($counting);

    $manager = new TelemetryManager(
        enabled: true,
        registry: new Registry($buffered, []),
        tracer: new Tracer,
    );
    $manager->addExporter(new CollectingExporter);

    $manager->counter('orders.created')->inc(3);

    expect($counting->writes)->toBe(0);

    $manager->flush();

    expect($counting->writes)->toBe(1);
});
