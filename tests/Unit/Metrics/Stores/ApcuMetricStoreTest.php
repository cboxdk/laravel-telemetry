<?php

declare(strict_types=1);

use Cbox\Telemetry\Metrics\MetricDefinition;
use Cbox\Telemetry\Metrics\MetricType;
use Cbox\Telemetry\Metrics\Stores\ApcuMetricStore;

uses()->group('apcu');

beforeEach(function () {
    if (! extension_loaded('apcu') || ! apcu_enabled()) {
        $this->markTestSkipped('APCu is not available (needs ext-apcu and apc.enable_cli=1).');
    }

    $this->store = new ApcuMetricStore('telemetry_test_'.bin2hex(random_bytes(4)));
});

afterEach(function () {
    if (isset($this->store)) {
        $this->store->wipe();
    }
});

it('aggregates float counter increments', function () {
    $definition = new MetricDefinition('orders.created', MetricType::Counter, 'Orders created');

    $this->store->incrementCounter($definition, ['tenant' => 'a'], 1.5);
    $this->store->incrementCounter($definition, ['tenant' => 'a'], 2.25);

    $family = $this->store->collect()[0];

    expect($family->definition->description)->toBe('Orders created')
        ->and($family->samples[0]->value)->toBe(3.75);
});

it('sets gauges and records histograms', function () {
    $this->store->setGauge(new MetricDefinition('queue.depth', MetricType::Gauge), [], 42.5);

    $histogram = new MetricDefinition('req.duration', MetricType::Histogram, buckets: [10.0, 100.0]);
    $this->store->recordHistogram($histogram, [], 5);
    $this->store->recordHistogram($histogram, [], 500);

    $families = collect($this->store->collect())->keyBy(fn ($f) => $f->name());

    expect($families['queue.depth']->samples[0]->value)->toBe(42.5)
        ->and($families['req.duration']->samples[0]->bucketCounts)->toBe([1, 0, 1])
        ->and($families['req.duration']->samples[0]->sum)->toBe(505.0)
        ->and($families['req.duration']->samples[0]->count)->toBe(2);
});

it('wipes everything it wrote', function () {
    $this->store->incrementCounter(new MetricDefinition('a.b', MetricType::Counter), [], 1);

    $this->store->wipe();

    expect($this->store->collect())->toBeEmpty();
});
