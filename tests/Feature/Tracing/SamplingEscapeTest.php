<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Http\Middleware\Sample;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\SpanStatus;
use Cbox\Telemetry\Tracing\Tracer;
use Illuminate\Support\Facades\Route;

it('exports error spans from unsampled traces', function () {
    $tracer = new Tracer(sampleRate: 0.0, alwaysSampleErrors: true);

    // A healthy span in an unsampled trace: dropped.
    $tracer->span('healthy', fn () => null);

    // A failing span: escapes the sampling decision.
    try {
        $tracer->span('failing', fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }

    $spans = $tracer->drain();

    expect($spans)->toHaveCount(1)
        ->and($spans[0]->name)->toBe('failing')
        ->and($spans[0]->status())->toBe(SpanStatus::Error);
});

it('keeps error spans dropped when escape is disabled', function () {
    $tracer = new Tracer(sampleRate: 0.0, alwaysSampleErrors: false);

    try {
        $tracer->span('failing', fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }

    expect($tracer->drain())->toBeEmpty();
});

it('lets routes opt out of sampling with Sample::never', function () {
    $collector = new CollectingExporter;
    Telemetry::addExporter($collector);

    Route::get('/health-check', fn () => 'ok')->middleware(Sample::never());
    Route::get('/normal', fn () => 'ok');

    $this->get('/health-check')->assertOk();
    $this->get('/normal')->assertOk();

    Telemetry::flush();

    $names = collect($collector->batches())->flatMap(fn ($batch) => $batch->spans)->pluck('name');

    expect($names)->toContain('GET /normal')
        ->not->toContain('GET /health-check');
});

it('lets routes opt back in with Sample::always when the global rate is zero', function () {
    config()->set('telemetry.traces.sample_rate', 0.0);
    app()->forgetInstance(Tracer::class);
    app()->forgetInstance(TelemetryManager::class);

    $collector = new CollectingExporter;
    Telemetry::addExporter($collector);

    Route::get('/checkout', fn () => 'ok')->middleware(Sample::always());
    Route::get('/normal', fn () => 'ok');

    $this->get('/checkout')->assertOk();
    $this->get('/normal')->assertOk();

    Telemetry::flush();

    $names = collect($collector->batches())->flatMap(fn ($batch) => $batch->spans)->pluck('name');

    expect($names)->toContain('GET /checkout')
        ->not->toContain('GET /normal');
});

it('parses the Sample::rate() route parameter into a float rate', function () {
    config()->set('telemetry.traces.sample_rate', 0.0);
    app()->forgetInstance(Tracer::class);
    app()->forgetInstance(TelemetryManager::class);

    $collector = new CollectingExporter;
    Telemetry::addExporter($collector);

    // rate(1.0) must deterministically sample; the interesting part is the
    // string round-trip through the route-middleware parameter syntax.
    Route::get('/feed', fn () => 'ok')->middleware(Sample::rate(1.0));

    $this->get('/feed')->assertOk();

    Telemetry::flush();

    $names = collect($collector->batches())->flatMap(fn ($batch) => $batch->spans)->pluck('name');

    expect($names)->toContain('GET /feed');
});

it('re-decides sampling for the whole active trace including the open root', function () {
    $tracer = new Tracer(sampleRate: 1.0);

    $root = $tracer->startSpan('root');

    $tracer->resample(false);

    $tracer->span('child-after-resample', fn () => null);
    $root->end();

    expect($tracer->drain())->toBeEmpty();
});

it('propagates the overridden sampling decision downstream', function () {
    $tracer = new Tracer(sampleRate: 1.0);

    $tracer->startSpan('root');
    $tracer->resample(false);

    expect($tracer->currentTraceParent()->sampled)->toBeFalse();
});
