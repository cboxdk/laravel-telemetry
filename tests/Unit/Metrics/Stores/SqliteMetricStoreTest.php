<?php

declare(strict_types=1);

use Cbox\Telemetry\Metrics\Exemplar;
use Cbox\Telemetry\Metrics\MetricDefinition;
use Cbox\Telemetry\Metrics\MetricType;
use Cbox\Telemetry\Metrics\Stores\SqliteMetricStore;

beforeEach(function () {
    if (! extension_loaded('pdo_sqlite')) {
        $this->markTestSkipped('The sqlite metric store needs ext-pdo_sqlite.');
    }

    $this->path = sys_get_temp_dir().'/telemetry_test_'.bin2hex(random_bytes(6)).'/metrics.sqlite';
    $this->store = new SqliteMetricStore($this->path);
});

afterEach(function () {
    if (! isset($this->path)) {
        return;
    }

    foreach (glob($this->path.'*') ?: [] as $file) {
        @unlink($file);
    }

    if (is_dir(dirname($this->path))) {
        @rmdir(dirname($this->path));
    }
});

it('aggregates float counter increments', function () {
    $definition = new MetricDefinition('orders.created', MetricType::Counter, 'Orders created');

    $this->store->incrementCounter($definition, ['tenant' => 'a'], 1.5);
    $this->store->incrementCounter($definition, ['tenant' => 'a'], 2.25);

    $family = $this->store->collect()[0];

    expect($family->definition->description)->toBe('Orders created')
        ->and($family->samples[0]->labels)->toBe(['tenant' => 'a'])
        ->and($family->samples[0]->value)->toBe(3.75);
});

it('sets gauges and records histograms', function () {
    $this->store->setGauge(new MetricDefinition('queue.depth', MetricType::Gauge), [], 42.5);
    $this->store->addGauge(new MetricDefinition('queue.depth', MetricType::Gauge), [], -2.5);

    $histogram = new MetricDefinition('req.duration', MetricType::Histogram, buckets: [10.0, 100.0]);
    $this->store->recordHistogram($histogram, [], 5);
    $this->store->recordHistogram($histogram, [], 500);

    $families = collect($this->store->collect())->keyBy(fn ($f) => $f->name());

    expect($families['queue.depth']->samples[0]->value)->toBe(40.0)
        ->and($families['req.duration']->samples[0]->bucketCounts)->toBe([1, 0, 1])
        ->and($families['req.duration']->samples[0]->sum)->toBe(505.0)
        ->and($families['req.duration']->samples[0]->count)->toBe(2);
});

it('restores bucket bounds as floats after a round trip', function () {
    // JSON writes 10.0 as `10`; a bound that comes back as an int would
    // change the rendered le= label.
    $this->store->recordHistogram(
        new MetricDefinition('req.duration', MetricType::Histogram, buckets: [10.0, 100.0]),
        [],
        5,
    );

    expect((new SqliteMetricStore($this->path))->collect()[0]->definition->buckets)->toBe([10.0, 100.0]);
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

it('merges pre-aggregated histogram data', function () {
    $histogram = new MetricDefinition('req.duration', MetricType::Histogram, buckets: [10.0, 100.0]);

    $this->store->mergeHistogram($histogram, [], [2, 1, 0], 40.0, 3);
    $this->store->mergeHistogram($histogram, [], [1, 0, 1], 510.0, 2);

    $sample = $this->store->collect()[0]->samples[0];

    expect($sample->bucketCounts)->toBe([3, 1, 1])
        ->and($sample->sum)->toBe(550.0)
        ->and($sample->count)->toBe(5);
});

it('survives the process that wrote it', function () {
    $this->store->incrementCounter(new MetricDefinition('orders.created', MetricType::Counter), ['tenant' => 'a'], 3);
    $this->store->recordHistogram(
        new MetricDefinition('req.duration', MetricType::Histogram, buckets: [10.0]),
        [],
        5,
        new Exemplar('trace-1', 5.0, 1_000),
    );

    // A phone kills the app; the next launch is a brand new process.
    $families = collect((new SqliteMetricStore($this->path))->collect())->keyBy(fn ($f) => $f->name());

    expect($families['orders.created']->samples[0]->value)->toBe(3.0)
        ->and($families['req.duration']->samples[0]->count)->toBe(1)
        ->and($families['req.duration']->samples[0]->exemplar?->traceId)->toBe('trace-1');
});

it('accumulates writes from separate connections', function () {
    $counter = new MetricDefinition('orders.created', MetricType::Counter);

    // A desktop app's server and its queue worker, writing the same series.
    $this->store->incrementCounter($counter, [], 2);
    (new SqliteMetricStore($this->path))->incrementCounter($counter, [], 5);

    expect($this->store->collect()[0]->samples[0]->value)->toBe(7.0);
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

    // The first write sets this instance's per-process meta memo.
    $this->store->incrementCounter($counter, [], 1);
    $this->store->recordHistogram($histogram, [], 5, new Exemplar('trace-1', 5.0, 1_000));

    // Another instance (telemetry:flush --wipe) resets the store.
    (new SqliteMetricStore($this->path))->wipe();

    expect($this->store->collect())->toBeEmpty();

    // The warm worker writes again WITHOUT re-writing meta — the metrics
    // must still be collectable, and the wiped exemplar must not resurface.
    $this->store->incrementCounter($counter, [], 5);
    $this->store->recordHistogram($histogram, [], 7);

    $families = collect($this->store->collect())->keyBy(fn ($f) => $f->name());

    expect($families)->toHaveKeys(['orders.created', 'req.duration'])
        ->and($families['orders.created']->definition->description)->toBe('Orders created')
        ->and($families['orders.created']->samples[0]->value)->toBe(5.0)
        ->and($families['req.duration']->samples[0]->count)->toBe(1)
        ->and($families['req.duration']->samples[0]->bucketCounts)->toBe([1, 0])
        ->and($families['req.duration']->samples[0]->exemplar)->toBeNull();
});

it('restarts the cumulative start timestamp at a wipe', function () {
    $counter = new MetricDefinition('orders.created', MetricType::Counter);

    $this->store->incrementCounter($counter, [], 1);
    $before = $this->store->collect()[0]->startUnixNano;

    $this->store->wipe();
    $this->store->incrementCounter($counter, [], 1);

    expect($this->store->collect()[0]->startUnixNano)->toBeGreaterThan($before);
});

it('forgets a single series without touching its siblings', function () {
    $counter = new MetricDefinition('worker.memory', MetricType::Counter);
    $histogram = new MetricDefinition('req.duration', MetricType::Histogram, buckets: [10.0]);

    $this->store->incrementCounter($counter, ['pid' => '1'], 1);
    $this->store->incrementCounter($counter, ['pid' => '2'], 2);
    $this->store->recordHistogram($histogram, ['pid' => '1'], 5);
    $this->store->recordHistogram($histogram, ['pid' => '2'], 5);

    $this->store->forgetSeries($counter, ['pid' => '1']);
    $this->store->forgetSeries($histogram, ['pid' => '1']);

    $families = collect($this->store->collect())->keyBy(fn ($f) => $f->name());

    expect($families['worker.memory']->samples)->toHaveCount(1)
        ->and($families['worker.memory']->samples[0]->labels)->toBe(['pid' => '2'])
        ->and($families['req.duration']->samples)->toHaveCount(1)
        ->and($families['req.duration']->samples[0]->bucketCounts)->toBe([1, 0]);
});

it('drops a family whose last series was forgotten', function () {
    $counter = new MetricDefinition('worker.memory', MetricType::Counter);

    $this->store->incrementCounter($counter, ['pid' => '1'], 1);
    $this->store->forgetSeries($counter, ['pid' => '1']);

    expect($this->store->collect())->toBeEmpty();
});
