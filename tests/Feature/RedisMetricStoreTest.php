<?php

declare(strict_types=1);

use Cbox\Telemetry\Metrics\MetricDefinition;
use Cbox\Telemetry\Metrics\MetricType;
use Cbox\Telemetry\Metrics\Stores\RedisMetricStore;
use Illuminate\Contracts\Redis\Factory;

uses()->group('redis');

beforeEach(function () {
    if (! extension_loaded('redis')) {
        $this->markTestSkipped('ext-redis is not installed.');
    }

    try {
        app(Factory::class)->connection()->ping();
    } catch (Throwable) {
        $this->markTestSkipped('No Redis server available on the default connection.');
    }

    $this->prefix = 'telemetry_test_'.bin2hex(random_bytes(4));
    $this->store = new RedisMetricStore(app(Factory::class), 'default', $this->prefix);
});

afterEach(function () {
    if (isset($this->store)) {
        $this->store->wipe();
    }
});

it('aggregates counter increments across store instances', function () {
    $definition = new MetricDefinition('orders.created', MetricType::Counter, 'Orders created');

    // Two instances simulate two PHP processes sharing the same Redis.
    $other = new RedisMetricStore(app(Factory::class), 'default', $this->prefix);

    $this->store->incrementCounter($definition, ['tenant' => 'a'], 1);
    $other->incrementCounter($definition, ['tenant' => 'a'], 2);
    $other->incrementCounter($definition, ['tenant' => 'b'], 10);

    $family = collect($this->store->collect())->firstWhere(fn ($f) => $f->name() === 'orders.created');

    $samples = collect($family->samples)->keyBy(fn ($sample) => $sample->labels['tenant']);

    expect($family->definition->description)->toBe('Orders created')
        ->and($samples['a']->value)->toBe(3.0)
        ->and($samples['b']->value)->toBe(10.0);
});

it('keeps the last gauge value per labelset', function () {
    $definition = new MetricDefinition('queue.depth', MetricType::Gauge);

    $this->store->setGauge($definition, ['queue' => 'default'], 10);
    $this->store->setGauge($definition, ['queue' => 'default'], 4);

    $family = $this->store->collect()[0];

    expect($family->samples[0]->value)->toBe(4.0);
});

it('adjusts gauges atomically across store instances', function () {
    $definition = new MetricDefinition('jobs.in_flight', MetricType::Gauge);
    $other = new RedisMetricStore(app(Factory::class), 'default', $this->prefix);

    $this->store->addGauge($definition, [], 3);
    $other->addGauge($definition, [], 2);
    $this->store->addGauge($definition, [], -1);

    expect($this->store->collect()[0]->samples[0]->value)->toBe(4.0);
});

it('round-trips histograms with buckets, sum and count', function () {
    $definition = new MetricDefinition('req.duration', MetricType::Histogram, unit: 'ms', buckets: [10.0, 100.0]);

    $this->store->recordHistogram($definition, ['route' => '/'], 5);
    $this->store->recordHistogram($definition, ['route' => '/'], 50);
    $this->store->recordHistogram($definition, ['route' => '/'], 5000);

    $sample = $this->store->collect()[0]->samples[0];

    expect($sample->labels)->toBe(['route' => '/'])
        ->and($sample->bucketCounts)->toBe([1, 1, 1])
        ->and($sample->count)->toBe(3)
        ->and($sample->sum)->toBe(5055.0)
        ->and($this->store->collect()[0]->definition->buckets)->toBe([10.0, 100.0]);
});

it('survives label values containing separators and unicode', function () {
    $definition = new MetricDefinition('log.lines', MetricType::Counter);
    $labels = ['source' => 'a:b|c "d" æøå', 'path' => '/x/{id}'];

    $this->store->incrementCounter($definition, $labels, 1);

    // Labels are stored canonically (sorted by key).
    expect($this->store->collect()[0]->samples[0]->labels)
        ->toBe(['path' => '/x/{id}', 'source' => 'a:b|c "d" æøå']);
});

it('wipes everything it wrote', function () {
    $this->store->incrementCounter(new MetricDefinition('a.b', MetricType::Counter), [], 1);
    $this->store->recordHistogram(new MetricDefinition('c.d', MetricType::Histogram, buckets: [1.0]), [], 2);

    $this->store->wipe();

    expect($this->store->collect())->toBeEmpty();
});
