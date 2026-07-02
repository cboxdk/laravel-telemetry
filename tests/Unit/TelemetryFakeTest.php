<?php

declare(strict_types=1);

use Cbox\Telemetry\Contracts\TelemetryProvider;
use Cbox\Telemetry\Metrics\Registry;
use Cbox\Telemetry\Testing\TelemetryFake;
use PHPUnit\Framework\AssertionFailedError;

it('asserts counter increments with labels', function () {
    $fake = new TelemetryFake;

    $fake->counter('orders.created')->inc(2, ['tenant' => 'acme']);

    $fake->assertCounterIncremented('orders.created');
    $fake->assertCounterIncremented('orders.created', ['tenant' => 'acme']);

    expect($fake->counterValue('orders.created', ['tenant' => 'acme']))->toBe(2.0);
});

it('fails when a counter was never incremented', function () {
    (new TelemetryFake)->assertCounterIncremented('orders.created');
})->throws(AssertionFailedError::class);

it('asserts gauges, including observable gauges', function () {
    $fake = new TelemetryFake;

    $fake->gauge('cache.keys')->set(120);
    $fake->gauge('queue.depth', fn () => 42.0);

    $fake->assertGaugeSet('cache.keys');

    expect($fake->gaugeValue('cache.keys'))->toBe(120.0)
        ->and($fake->gaugeValue('queue.depth'))->toBe(42.0);
});

it('asserts histogram recordings', function () {
    $fake = new TelemetryFake;

    $fake->histogram('checkout.duration')->record(35.5, ['step' => 'payment']);

    $fake->assertHistogramRecorded('checkout.duration');
    $fake->assertHistogramRecorded('checkout.duration', ['step' => 'payment']);

    expect($fake->histogramCount('checkout.duration', ['step' => 'payment']))->toBe(1);
});

it('asserts recorded spans with a matcher', function () {
    $fake = new TelemetryFake;

    $fake->span('import.customers', fn ($span) => $span->setAttribute('rows', 500));

    $fake->assertSpanRecorded('import.customers');
    $fake->assertSpanRecorded('import.customers', fn ($span) => $span->attributes()['rows'] === 500);

    expect($fake->recordedSpans('import.customers'))->toHaveCount(1);
});

it('asserts emitted events', function () {
    $fake = new TelemetryFake;

    $fake->event('autoscale.decision', ['workers' => 5]);

    $fake->assertEventEmitted('autoscale.decision');
    $fake->assertEventEmitted('autoscale.decision', fn ($event) => $event->attributes['workers'] === 5);
});

it('correlates events to the active span', function () {
    $fake = new TelemetryFake;

    $fake->span('work', fn () => $fake->event('milestone'));

    $event = $fake->recordedEvents('milestone')[0];
    $span = $fake->recordedSpans('work')[0];

    expect($event->traceId)->toBe($span->traceId)
        ->and($event->spanId)->toBe($span->spanId);
});

it('lets packages test their providers end to end', function () {
    $provider = new class implements TelemetryProvider
    {
        public function name(): string
        {
            return 'cbox.queue-metrics';
        }

        public function register(Registry $registry): void
        {
            $registry->gauge('queue.depth', fn () => [[12, ['queue' => 'default']]]);
            $registry->counter('queue.jobs.processed');
        }
    };

    $fake = new TelemetryFake;
    $fake->provider($provider);

    // The app increments the provider-declared counter somewhere.
    $fake->counter('queue.jobs.processed')->inc();

    $families = collect($fake->collect())->keyBy(fn ($family) => $family->name());

    expect($families)->toHaveKeys(['queue.depth', 'queue.jobs.processed'])
        ->and($families['queue.depth']->samples[0]->value)->toBe(12.0);
});
