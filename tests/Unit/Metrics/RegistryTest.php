<?php

declare(strict_types=1);

use Cbox\Telemetry\Exceptions\InstrumentTypeMismatch;
use Cbox\Telemetry\Exceptions\InvalidMetricName;
use Cbox\Telemetry\Metrics\Instruments\Gauge;
use Cbox\Telemetry\Metrics\Instruments\ObservableGauge;
use Cbox\Telemetry\Metrics\MetricType;
use Cbox\Telemetry\Metrics\Registry;
use Cbox\Telemetry\Metrics\Stores\ArrayMetricStore;

function registry(): Registry
{
    return new Registry(new ArrayMetricStore, [10, 100, 1000]);
}

it('memoizes instruments by name', function () {
    $registry = registry();

    expect($registry->counter('orders.created'))->toBe($registry->counter('orders.created'));
});

it('rejects invalid metric names', function () {
    registry()->counter('Bad Name!');
})->throws(InvalidMetricName::class);

it('rejects reusing a name across instrument types', function () {
    $registry = registry();

    $registry->counter('orders.created');
    $registry->histogram('orders.created');
})->throws(InstrumentTypeMismatch::class);

it('returns a push gauge without a callback and an observable with one', function () {
    $registry = registry();

    expect($registry->gauge('cache.keys'))->toBeInstanceOf(Gauge::class)
        ->and($registry->gauge('queue.depth', fn () => 42))->toBeInstanceOf(ObservableGauge::class);
});

it('collects push metrics and observable gauges together', function () {
    $registry = registry();

    $registry->counter('orders.created')->inc(3);
    $registry->gauge('queue.depth', fn () => 42.0);

    $families = collect($registry->collect())->keyBy(fn ($family) => $family->name());

    expect($families)->toHaveCount(2)
        ->and($families['orders.created']->samples[0]->value)->toBe(3.0)
        ->and($families['queue.depth']->samples[0]->value)->toBe(42.0);
});

it('supports multi-series observable gauges', function () {
    $registry = registry();

    $registry->gauge('queue.depth', fn () => [
        [12, ['queue' => 'default']],
        [3, ['queue' => 'mail']],
    ]);

    $family = $registry->observe()[0];

    expect($family->samples)->toHaveCount(2)
        ->and($family->samples[0]->labels)->toBe(['queue' => 'default'])
        ->and($family->samples[0]->value)->toBe(12.0)
        ->and($family->samples[1]->labels)->toBe(['queue' => 'mail']);
});

it('drops only the failing observable, never the whole scrape', function () {
    $registry = registry();

    $registry->gauge('will.fail', fn () => throw new RuntimeException('source down'));
    $registry->gauge('will.work', fn () => 1.0);

    $families = $registry->observe();

    expect($families)->toHaveCount(1)
        ->and($families[0]->name())->toBe('will.work');
});

it('applies default buckets to histograms', function () {
    $registry = registry();

    expect($registry->histogram('checkout.duration')->definition()->buckets)->toBe([10, 100, 1000])
        ->and($registry->histogram('custom.duration', buckets: [1.0, 2.0])->definition()->buckets)->toBe([1.0, 2.0]);
});

it('push gauges can increment and decrement atomically', function () {
    $registry = registry();

    $gauge = $registry->gauge('jobs.in_flight');
    $gauge->increment(labels: ['queue' => 'default']);
    $gauge->increment(2, ['queue' => 'default']);
    $gauge->decrement(labels: ['queue' => 'default']);

    $family = $registry->collect()[0];

    expect($family->samples[0]->value)->toBe(2.0);
});

it('counters ignore negative increments', function () {
    $registry = registry();

    $counter = $registry->counter('orders.created');
    $counter->inc(5);
    $counter->inc(-3);

    expect($registry->collect()[0]->samples[0]->value)->toBe(5.0);
});

it('histograms can time closures', function () {
    $registry = registry();

    $result = $registry->histogram('work.duration')->time(fn () => 'done');

    $family = $registry->collect()[0];

    expect($result)->toBe('done')
        ->and($family->type())->toBe(MetricType::Histogram)
        ->and($family->samples[0]->count)->toBe(1);
});
