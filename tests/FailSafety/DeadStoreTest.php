<?php

declare(strict_types=1);

use Cbox\Telemetry\Contracts\MetricStore;
use Cbox\Telemetry\Metrics\Exemplar;
use Cbox\Telemetry\Metrics\MetricDefinition;
use Cbox\Telemetry\Metrics\MetricType;
use Cbox\Telemetry\Metrics\Stores\ArrayMetricStore;
use Cbox\Telemetry\Metrics\Stores\BufferedMetricStore;

/**
 * What a store outage does to the process that is trying to write to
 * it. An observability package that exhausts a worker's memory because
 * Redis is down has turned a dashboard problem into an application
 * problem, which is the one thing it must not do.
 */
function alwaysFailing(int &$attempts): MetricStore
{
    return new class($attempts) implements MetricStore
    {
        public function __construct(private int &$attempts) {}

        public function incrementCounter(MetricDefinition $definition, array $labels, float $by): void
        {
            $this->attempts++;

            throw new RuntimeException('the store is down');
        }

        public function setGauge(MetricDefinition $definition, array $labels, float $value): void
        {
            $this->attempts++;

            throw new RuntimeException('the store is down');
        }

        public function addGauge(MetricDefinition $definition, array $labels, float $delta): void {}

        public function recordHistogram(MetricDefinition $definition, array $labels, float $value, ?Exemplar $exemplar = null): void {}

        public function mergeHistogram(MetricDefinition $definition, array $labels, array $bucketCounts, float $sum, int $count, ?Exemplar $exemplar = null): void {}

        public function collect(): array
        {
            return [];
        }

        public function wipe(): void {}

        public function forgetSeries(MetricDefinition $definition, array $labels): void {}
    };
}

it('stops growing when the store it buffers for is gone', function () {
    // The buffer retains whatever a failed flush could not deliver, so
    // during an outage it grows by every distinct series the
    // application touches. Twenty thousand of them, in a worker that
    // lives for hours, is the worker.
    $attempts = 0;
    $buffer = new BufferedMetricStore(alwaysFailing($attempts), maxPending: 10, maxSeries: 100);
    $definition = new MetricDefinition('orders.created', MetricType::Counter);

    for ($i = 0; $i < 20_000; $i++) {
        try {
            $buffer->incrementCounter($definition, ['tenant' => "t{$i}"], 1.0);
        } catch (Throwable) {
            // The caller's guard. What matters is what is retained.
        }
    }

    $series = (new ReflectionProperty(BufferedMetricStore::class, 'counters'))->getValue($buffer);

    expect(count($series['orders.created']['series'] ?? []))->toBeLessThanOrEqual(100)
        // And it says how much it threw away, because "metrics are
        // missing" has to be answerable.
        ->and($buffer->droppedObservations())->toBeGreaterThan(19_000);
});

it('does not retry a dead store on every single observation', function () {
    // Each retry pays the backend's connect timeout, on the request
    // path. Rediscovering the same outage twenty thousand times is its
    // own outage.
    $attempts = 0;
    $buffer = new BufferedMetricStore(alwaysFailing($attempts), maxPending: 1, maxSeries: 10_000, cooldownSeconds: 60);
    $definition = new MetricDefinition('orders.created', MetricType::Counter);

    for ($i = 0; $i < 5_000; $i++) {
        try {
            $buffer->incrementCounter($definition, ['tenant' => "t{$i}"], 1.0);
        } catch (Throwable) {
        }
    }

    // One attempt, then the cooldown holds for the rest of the loop.
    expect($attempts)->toBeLessThan(5);
});

it('keeps aggregating the series it already has', function () {
    // A bounded-label application must lose nothing: the cap drops NEW
    // series, and an existing one keeps counting.
    $attempts = 0;
    $buffer = new BufferedMetricStore(alwaysFailing($attempts), maxPending: 1_000_000, maxSeries: 1);
    $definition = new MetricDefinition('orders.created', MetricType::Counter);

    for ($i = 0; $i < 100; $i++) {
        $buffer->incrementCounter($definition, ['queue' => 'default'], 1.0);
    }

    $series = (new ReflectionProperty(BufferedMetricStore::class, 'counters'))->getValue($buffer);

    expect($series['orders.created']['series'])->toHaveCount(1)
        ->and(array_values($series['orders.created']['series'])[0])->toBe(100.0)
        ->and($buffer->droppedObservations())->toBe(0);
});

it('does not let a sum reach infinity and take the buckets with it', function () {
    // Each observation is finite; their total is not. The buffer adds
    // them up before any store sees them, and Redis then refuses the
    // sum AFTER the buckets have been counted — a series whose buckets
    // outnumber its count.
    $inner = new ArrayMetricStore;
    $buffer = new BufferedMetricStore($inner, maxPending: 1_000);
    $definition = new MetricDefinition('work.duration', MetricType::Histogram, buckets: [1.0, 2.0]);

    $buffer->recordHistogram($definition, [], PHP_FLOAT_MAX);
    $buffer->recordHistogram($definition, [], PHP_FLOAT_MAX);
    $buffer->flushBuffer();

    $family = collect($inner->collect())->firstWhere(fn ($f) => $f->name() === 'work.duration');
    $sample = $family->samples[0];

    expect(is_finite($sample->sum))->toBeTrue()
        ->and(array_sum($sample->bucketCounts))->toBe($sample->count);
});
