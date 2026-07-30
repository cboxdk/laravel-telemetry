<?php

declare(strict_types=1);

use Cbox\Telemetry\Contracts\MetricStore;
use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Instrumentation\NativeScreenInstrumentation;
use Cbox\Telemetry\Metrics\Exemplar;
use Cbox\Telemetry\Metrics\MetricDefinition;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanStatus;

beforeEach(function () {
    Telemetry::fake();

    $this->instrumentation = new NativeScreenInstrumentation(app());
});

it('emits a view event and a counter when a screen opens', function () {
    $this->instrumentation->aroundScreen('App\NativeComponents\IkeaCart', fn () => null);

    Telemetry::assertEventEmitted('screen.view', fn ($event) => ($event->attributes['screen.name'] ?? null) === 'IkeaCart');
    Telemetry::assertCounterIncremented('screen.views', ['screen' => 'IkeaCart']);
});

it('records how long the screen was open, even when the loop throws', function () {
    expect(fn () => $this->instrumentation->aroundScreen(
        'App\NativeComponents\IkeaCart',
        fn () => throw new RuntimeException('runloop died'),
    ))->toThrow(RuntimeException::class);

    // The screen still has to be measured and flushed — a screen that
    // crashed is the one you most want a duration for.
    Telemetry::assertHistogramRecorded('screen.view.duration', ['screen' => 'IkeaCart']);
});

it('returns whatever the wrapped work returns', function () {
    $result = $this->instrumentation->aroundInteraction('Screens\Counter', 'interaction', [], fn () => 'value');

    expect($result)->toBe('value');
});

it('spans an interaction with the screen and event type', function () {
    $this->instrumentation->aroundInteraction(
        'App\NativeComponents\Counter',
        'interaction',
        ['type' => 3],
        fn () => null,
    );

    Telemetry::assertSpanRecorded(
        'screen.interaction',
        fn (Span $span): bool => $span->attributes()['screen.name'] === 'Counter'
            && $span->attributes()['screen.event.type'] === '3',
    );

    Telemetry::assertHistogramRecorded('screen.interaction.duration', [
        'screen' => 'Counter',
        'type' => 'interaction',
    ]);
});

it('falls back to an unknown interaction type when the event has none', function () {
    $this->instrumentation->aroundInteraction('Screens\Counter', 'native_event', [], fn () => null);

    Telemetry::assertSpanRecorded(
        'screen.native_event',
        fn (Span $span): bool => $span->attributes()['screen.event.type'] === 'unknown',
    );
});

it('marks the span failed and rethrows when the interaction throws', function () {
    $boom = new RuntimeException('tap handler exploded');

    expect(fn () => $this->instrumentation->aroundInteraction(
        'App\NativeComponents\Counter',
        'interaction',
        ['type' => 1],
        fn () => throw $boom,
    ))->toThrow($boom::class, 'tap handler exploded');

    Telemetry::assertSpanRecorded(
        'screen.interaction',
        fn (Span $span): bool => $span->status() === SpanStatus::Error,
    );

    Telemetry::assertCounterIncremented('screen.interactions.failed', ['screen' => 'Counter']);
});

it('passes straight through when native screens are not instrumented', function () {
    config()->set('telemetry.instrument.native_screens', false);

    expect($this->instrumentation->aroundScreen('Screens\Counter', fn () => 'ran'))->toBe('ran')
        ->and($this->instrumentation->aroundInteraction('Screens\Counter', 'interaction', [], fn () => 'ran'))->toBe('ran');

    Telemetry::assertEventNotEmitted('screen.view');
    Telemetry::assertCounterNotIncremented('screen.views');
    Telemetry::assertSpanNotRecorded('screen.interaction');
});

it('passes straight through when telemetry is disabled entirely', function () {
    config()->set('telemetry.enabled', false);

    expect($this->instrumentation->aroundInteraction('Screens\Counter', 'interaction', [], fn () => 'ran'))->toBe('ran');

    Telemetry::assertSpanNotRecorded('screen.interaction');
});

it('never lets a telemetry failure reach the app', function () {
    // A store that throws on every write stands in for a full disk.
    app()->bind(MetricStore::class, fn () => new class implements MetricStore
    {
        public function incrementCounter(MetricDefinition $definition, array $labels, float $by): void
        {
            throw new RuntimeException('disk full');
        }

        public function setGauge(MetricDefinition $definition, array $labels, float $value): void {}

        public function addGauge(MetricDefinition $definition, array $labels, float $delta): void {}

        public function recordHistogram(MetricDefinition $definition, array $labels, float $value, ?Exemplar $exemplar = null): void
        {
            throw new RuntimeException('disk full');
        }

        public function mergeHistogram(MetricDefinition $definition, array $labels, array $bucketCounts, float $sum, int $count, ?Exemplar $exemplar = null): void {}

        public function collect(): array
        {
            return [];
        }

        public function wipe(): void {}

        public function forgetSeries(MetricDefinition $definition, array $labels): void {}
    });

    $instrumentation = new NativeScreenInstrumentation(app());

    expect($instrumentation->aroundScreen('Screens\Counter', fn () => 'still ran'))->toBe('still ran')
        ->and($instrumentation->aroundInteraction('Screens\Counter', 'interaction', [], fn () => 'still ran'))->toBe('still ran');
});
