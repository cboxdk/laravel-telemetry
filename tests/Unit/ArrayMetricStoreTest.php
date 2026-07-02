<?php

declare(strict_types=1);

use Cbox\Telemetry\Metrics\MetricDefinition;
use Cbox\Telemetry\Metrics\MetricType;
use Cbox\Telemetry\Metrics\Stores\ArrayMetricStore;

it('aggregates counters per labelset', function () {
    $store = new ArrayMetricStore;
    $definition = new MetricDefinition('orders.created', MetricType::Counter);

    $store->incrementCounter($definition, ['tenant' => 'a'], 1);
    $store->incrementCounter($definition, ['tenant' => 'a'], 2);
    $store->incrementCounter($definition, ['tenant' => 'b'], 5);

    $samples = collect($store->collect()[0]->samples)->keyBy(fn ($sample) => $sample->labels['tenant']);

    expect($samples['a']->value)->toBe(3.0)
        ->and($samples['b']->value)->toBe(5.0);
});

it('treats label order as irrelevant', function () {
    $store = new ArrayMetricStore;
    $definition = new MetricDefinition('orders.created', MetricType::Counter);

    $store->incrementCounter($definition, ['a' => '1', 'b' => '2'], 1);
    $store->incrementCounter($definition, ['b' => '2', 'a' => '1'], 1);

    expect($store->collect()[0]->samples)->toHaveCount(1)
        ->and($store->collect()[0]->samples[0]->value)->toBe(2.0);
});

it('gauges keep the last written value', function () {
    $store = new ArrayMetricStore;
    $definition = new MetricDefinition('queue.depth', MetricType::Gauge);

    $store->setGauge($definition, [], 10);
    $store->setGauge($definition, [], 4);

    expect($store->collect()[0]->samples[0]->value)->toBe(4.0);
});

it('buckets histogram observations with a non-cumulative overflow slot', function () {
    $store = new ArrayMetricStore;
    $definition = new MetricDefinition('duration', MetricType::Histogram, buckets: [10.0, 100.0]);

    $store->recordHistogram($definition, [], 5);     // bucket 0
    $store->recordHistogram($definition, [], 10);    // bucket 0 (le boundary is inclusive)
    $store->recordHistogram($definition, [], 50);    // bucket 1
    $store->recordHistogram($definition, [], 5000);  // overflow

    $sample = $store->collect()[0]->samples[0];

    expect($sample->bucketCounts)->toBe([2, 1, 1])
        ->and($sample->count)->toBe(4)
        ->and($sample->sum)->toBe(5065.0);
});

it('wipes all state', function () {
    $store = new ArrayMetricStore;

    $store->incrementCounter(new MetricDefinition('orders.created', MetricType::Counter), [], 1);
    $store->wipe();

    expect($store->collect())->toBeEmpty();
});
