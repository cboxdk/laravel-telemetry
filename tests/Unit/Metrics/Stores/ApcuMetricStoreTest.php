<?php

declare(strict_types=1);

use Cbox\Telemetry\Metrics\Exemplar;
use Cbox\Telemetry\Metrics\MetricDefinition;
use Cbox\Telemetry\Metrics\MetricType;
use Cbox\Telemetry\Metrics\Stores\ApcuMetricStore;

uses()->group('apcu');

beforeEach(function () {
    if (! extension_loaded('apcu') || ! apcu_enabled()) {
        $this->markTestSkipped('APCu is not available (needs ext-apcu and apc.enable_cli=1).');
    }

    $this->prefix = 'telemetry_test_'.bin2hex(random_bytes(4));
    $this->store = new ApcuMetricStore($this->prefix);
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

it('keeps the latest exemplar for a histogram series', function () {
    $histogram = new MetricDefinition('req.duration', MetricType::Histogram, buckets: [10.0, 100.0]);

    $this->store->recordHistogram($histogram, [], 5, new Exemplar('trace-1', 5.0, 1_000));
    $this->store->recordHistogram($histogram, [], 50, new Exemplar('trace-2', 50.0, 2_000));
    $this->store->recordHistogram($histogram, [], 20); // no exemplar — does not clear the last one

    $sample = $this->store->collect()[0]->samples[0];

    expect($sample->exemplar?->traceId)->toBe('trace-2')
        ->and($sample->exemplar?->value)->toBe(50.0)
        ->and($sample->exemplar?->timeUnixNano)->toBe(2000);
});

it('wipes everything it wrote', function () {
    $this->store->incrementCounter(new MetricDefinition('a.b', MetricType::Counter), [], 1);
    $this->store->recordHistogram(
        new MetricDefinition('c.d', MetricType::Histogram, buckets: [1.0]),
        [],
        2,
        new Exemplar('trace-1', 2.0, 1_000),
    );

    $this->store->wipe();

    expect($this->store->collect())->toBeEmpty();
});

it('keeps warm workers visible after a wipe from another instance', function () {
    $counter = new MetricDefinition('orders.created', MetricType::Counter, 'Orders created');
    $histogram = new MetricDefinition('req.duration', MetricType::Histogram, buckets: [10.0]);

    // The first write sets this instance's per-process index() memo —
    // it plays the warm FPM worker.
    $this->store->incrementCounter($counter, [], 1);
    $this->store->recordHistogram($histogram, [], 5, new Exemplar('trace-1', 5.0, 1_000));

    // Another instance (telemetry:flush --wipe) resets the store.
    (new ApcuMetricStore($this->prefix))->wipe();

    expect($this->store->collect())->toBeEmpty();

    // The warm worker writes again WITHOUT re-indexing — the metrics must
    // still be collectable, and the wiped exemplar must not resurface.
    $this->store->incrementCounter($counter, [], 5);
    $this->store->recordHistogram($histogram, [], 7);

    $families = collect($this->store->collect())->keyBy(fn ($f) => $f->name());

    expect($families)->toHaveKeys(['orders.created', 'req.duration'])
        ->and($families['orders.created']->samples[0]->value)->toBe(5.0)
        ->and($families['req.duration']->samples[0]->count)->toBe(1)
        ->and($families['req.duration']->samples[0]->bucketCounts)->toBe([1, 0])
        ->and($families['req.duration']->samples[0]->exemplar)->toBeNull();
});
