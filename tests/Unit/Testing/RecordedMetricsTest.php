<?php

declare(strict_types=1);

use Cbox\Telemetry\Contracts\TelemetryProvider;
use Cbox\Telemetry\Metrics\MetricType;
use Cbox\Telemetry\Metrics\Registry;
use Cbox\Telemetry\Testing\RecordedSample;
use Cbox\Telemetry\Testing\TelemetryFake;
use PHPUnit\Framework\AssertionFailedError;

/**
 * The shape the Hubhus test wanted: one counter, one label whose value is
 * the thing under test.
 */
function recordTransitStages(TelemetryFake $fake, string ...$stages): void
{
    foreach ($stages as $stage) {
        $fake->counter('transit.pairs')->inc(1, ['stage' => $stage]);
    }
}

it('exposes recorded metric samples the way it exposes spans and events', function () {
    $fake = new TelemetryFake;

    $fake->counter('orders.created')->inc(2, ['tenant' => 'acme']);
    $fake->gauge('cache.keys')->set(120);
    $fake->histogram('checkout.duration')->record(35.5, ['step' => 'payment']);

    $samples = $fake->recordedMetrics()->samples();

    expect($samples)->toHaveCount(3)
        ->and($samples[0])->toBeInstanceOf(RecordedSample::class);

    $counter = $fake->recordedMetrics('orders.created')->samples()[0];

    expect($counter->name)->toBe('orders.created')
        ->and($counter->type)->toBe(MetricType::Counter)
        ->and($counter->labels)->toBe(['tenant' => 'acme'])
        ->and($counter->value)->toBe(2.0)
        ->and($counter->count)->toBeNull()
        ->and($counter->label('tenant'))->toBe('acme')
        ->and($counter->label('missing'))->toBeNull()
        ->and($counter->describe())->toBe('orders.created{tenant="acme"} 2');
});

it('carries count and sum for histogram series', function () {
    $fake = new TelemetryFake;

    $fake->histogram('checkout.duration')->record(10, ['step' => 'payment']);
    $fake->histogram('checkout.duration')->record(30, ['step' => 'payment']);

    $sample = $fake->recordedMetrics('checkout.duration')->samples()[0];

    expect($sample->type)->toBe(MetricType::Histogram)
        ->and($sample->count)->toBe(2)
        ->and($sample->value)->toBe(40.0)
        ->and($sample->histogram?->bounds)->not->toBeEmpty()
        ->and($sample->describe())->toBe('checkout.duration{step="payment"} count=2 sum=40');
});

it('answers which values a label was recorded with in one call', function () {
    $fake = new TelemetryFake;

    recordTransitStages($fake, 'provider', 'cache', 'cache');

    expect($fake->recordedMetrics('transit.pairs')->labelValues('stage'))->toBe(['cache', 'provider'])
        ->and($fake->metricLabelValues('transit.pairs', 'stage'))->toBe(['cache', 'provider'])
        ->and($fake->recordedMetrics('transit.pairs')->labelValues('absent'))->toBe([]);
});

it('pins the full observed label set', function () {
    $fake = new TelemetryFake;

    recordTransitStages($fake, 'cache', 'provider', 'unresolved');

    $fake->recordedMetrics('transit.pairs')->assertLabelValues('stage', ['unresolved', 'cache', 'provider']);
    $fake->assertMetricLabelValues('transit.pairs', 'stage', ['unresolved', 'cache', 'provider']);
});

it('fails when a label value the test expected was never observed', function () {
    $fake = new TelemetryFake;

    // The bug this API exists for: every stage is a *member* of the
    // vocabulary, so a membership check passes on one branch alone.
    recordTransitStages($fake, 'unresolved');

    $fake->recordedMetrics('transit.pairs')
        ->assertLabelValues('stage', ['cache', 'estimated', 'provider', 'postal_code_fallback', 'unresolved']);
})->throws(AssertionFailedError::class, 'never observed: cache, estimated, postal_code_fallback, provider');

it('fails when an unexpected label value shows up', function () {
    $fake = new TelemetryFake;

    recordTransitStages($fake, 'cache', 'typo_stage');

    $fake->recordedMetrics('transit.pairs')->assertLabelValues('stage', ['cache']);
})->throws(AssertionFailedError::class, 'unexpected: typo_stage');

it('names the metric under test in the failure message', function () {
    $fake = new TelemetryFake;

    recordTransitStages($fake, 'cache');

    $fake->recordedMetrics('transit.pairs')->assertLabelValues('stage', ['provider']);
})->throws(AssertionFailedError::class, 'Metric [transit.pairs] observed label [stage] values [cache]');

it('counts series so a cardinality explosion fails the test', function () {
    $fake = new TelemetryFake;

    foreach (range(1, 12) as $id) {
        $fake->counter('orders.created')->inc(1, ['tenant' => 'acme', 'user' => (string) $id]);
    }

    $metrics = $fake->recordedMetrics('orders.created');

    expect($metrics->seriesCount())->toBe(12)
        ->and($metrics)->toHaveCount(12)
        ->and($metrics->total())->toBe(12.0)
        ->and($metrics->labelCardinality())->toBe(['user' => 12, 'tenant' => 1]);

    $metrics->assertSeriesCount(12)->assertCardinalityBelow(50);
});

it('reports the worst label when a cardinality budget is blown', function () {
    $fake = new TelemetryFake;

    foreach (range(1, 12) as $id) {
        $fake->counter('orders.created')->inc(1, ['tenant' => 'acme', 'user' => (string) $id]);
    }

    $fake->recordedMetrics('orders.created')->assertCardinalityBelow(10);
})->throws(AssertionFailedError::class, 'Distinct values per label: user (12), tenant (1).');

it('budgets a single label', function () {
    $fake = new TelemetryFake;

    recordTransitStages($fake, 'cache', 'provider');

    $fake->recordedMetrics('transit.pairs')->assertLabelCardinalityBelow('stage', 6);

    expect(fn () => $fake->recordedMetrics('transit.pairs')->assertLabelCardinalityBelow('stage', 2))
        ->toThrow(AssertionFailedError::class, 'observed 2 distinct values for label [stage]');
});

it('fails an exact series count with the breakdown attached', function () {
    $fake = new TelemetryFake;

    recordTransitStages($fake, 'cache', 'provider');

    $fake->recordedMetrics('transit.pairs')->assertSeriesCount(5);
})->throws(AssertionFailedError::class, 'recorded 2 series, expected 5');

it('filters by metric, type and labels', function () {
    $fake = new TelemetryFake;

    $fake->counter('orders.created')->inc(1, ['tenant' => 'acme']);
    $fake->counter('orders.created')->inc(3, ['tenant' => 'globex']);
    $fake->gauge('cache.keys')->set(120);

    $all = $fake->recordedMetrics();

    expect($all->names())->toBe(['cache.keys', 'orders.created'])
        ->and($all->ofType(MetricType::Gauge)->names())->toBe(['cache.keys'])
        ->and($all->forMetric('orders.created')->total())->toBe(4.0)
        ->and($all->withLabels(['tenant' => 'globex'])->samples()[0]->value)->toBe(3.0)
        ->and($all->filter(fn ($sample) => $sample->value > 100.0)->names())->toBe(['cache.keys'])
        ->and($all->forMetric('nothing.here')->isEmpty())->toBeTrue()
        ->and($all->forMetric('orders.created')->labelSets())
        ->toBe([['tenant' => 'acme'], ['tenant' => 'globex']]);
});

it('is iterable', function () {
    $fake = new TelemetryFake;

    recordTransitStages($fake, 'cache', 'provider');

    $stages = [];

    foreach ($fake->recordedMetrics('transit.pairs') as $sample) {
        $stages[] = $sample->label('stage');
    }

    expect($stages)->toBe(['cache', 'provider']);
});

it('includes observable gauges and provider-registered metrics', function () {
    $provider = new class implements TelemetryProvider
    {
        public function name(): string
        {
            return 'cbox.queue-metrics';
        }

        public function register(Registry $registry): void
        {
            $registry->gauge('queue.depth', fn () => [[12, ['queue' => 'default']]]);
            $registry->counter('queue.jobs.processed')->inc(4, ['queue' => 'default']);
        }
    };

    $fake = new TelemetryFake;
    $fake->provider($provider);

    expect($fake->recordedMetrics('queue.depth')->labelValues('queue'))->toBe(['default'])
        ->and($fake->recordedMetrics('queue.depth')->samples()[0]->value)->toBe(12.0);

    // Provider metrics assert like any other, boot and all.
    $fake->assertCounterIncremented('queue.jobs.processed', ['queue' => 'default']);

    expect($fake->counterValue('queue.jobs.processed', ['queue' => 'default']))->toBe(4.0);
});

it('describes an empty result without inventing a metric name', function () {
    (new TelemetryFake)->recordedMetrics('never.recorded')->assertLabelValues('stage', ['cache']);
})->throws(AssertionFailedError::class, 'No metric observed label [stage] values []');
