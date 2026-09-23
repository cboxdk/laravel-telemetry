<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Http\RequestPhases;
use Cbox\Telemetry\Metrics\HistogramSample;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanKind;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);

    Route::get('/phases', fn () => 'ok');
});

/** @return list<Span> */
function requestPhaseSpans(CollectingExporter $collector): array
{
    Telemetry::flush();

    $spans = [];

    foreach ($collector->batches() as $batch) {
        foreach ($batch->spans as $span) {
            $spans[] = $span;
        }
    }

    return $spans;
}

function requestPhaseDuration(): HistogramSample
{
    $family = collect(Telemetry::collect())->firstWhere(fn ($family) => $family->name() === 'http.server.request.duration');
    $sample = $family->samples[0];

    assert($sample instanceof HistogramSample);

    return $sample;
}

it('records every phase as a child of the request span', function () {
    $this->get('/phases')->assertOk();

    $spans = collect(requestPhaseSpans($this->collector));
    $server = $spans->firstWhere('kind', SpanKind::Server);
    $phases = $spans->filter(fn (Span $span) => str_starts_with($span->name, 'laravel.') && $span->name !== 'laravel.bootstrap');

    expect($phases->pluck('name')->all())->toBe(['laravel.routing', 'laravel.handler', 'laravel.send', 'laravel.terminate'])
        ->and($phases->every(fn (Span $span) => $span->parentSpanId === $server->spanId))->toBeTrue()
        ->and($server->attributes())->toHaveKeys([
            'laravel.routing_ms', 'laravel.handler_ms', 'laravel.send_ms', 'laravel.terminate_ms',
        ]);
});

it('keeps work after the response out of the request duration', function () {
    // The motivating case: answered at once, then 60 ms of deferred work.
    Route::get('/deferred', function () {
        defer(fn () => usleep(60_000));

        return 'ok';
    });

    $this->get('/deferred')->assertOk();

    $server = collect(requestPhaseSpans($this->collector))->firstWhere('kind', SpanKind::Server);

    $terminateMs = $server->attributes()['laravel.terminate_ms'];

    // Relative, not absolute, so a slow machine can't fail it: the metric
    // stops where the terminate phase starts. 1 ms covers the rounding.
    expect($terminateMs)->toBeGreaterThanOrEqual(60.0)
        ->and(requestPhaseDuration()->sum * 1000)->toBeLessThanOrEqual($server->durationMs() - $terminateMs + 1);
});

it('folds a phase whose boundary never fired into the next one', function () {
    // A 404 matches no route, so RouteMatched never fires.
    $this->get('/nowhere')->assertNotFound();

    $names = collect(requestPhaseSpans($this->collector))->pluck('name');

    expect($names)->not->toContain('laravel.routing')
        ->and($names)->toContain('laravel.handler')
        ->and($names)->toContain('laravel.terminate');
});

it('records no phases for an ignored path', function () {
    config()->set('telemetry.instrument.http_ignore_paths', ['phases']);

    $this->get('/phases')->assertOk();

    expect(collect(requestPhaseSpans($this->collector))->pluck('name'))->not->toContain('laravel.terminate');
});

it('lets go of the request span once the request ends', function () {
    $this->get('/phases')->assertOk();
    requestPhaseSpans($this->collector);

    // Events from outside any traced request (a console kernel, a later
    // ignored request) must not land on the finished span.
    app(RequestPhases::class)->terminating();

    expect(app(RequestPhases::class)->finish())->toBeNull();
});
