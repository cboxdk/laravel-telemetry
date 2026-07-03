<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Instrumentation\TracingEngine;
use Cbox\Telemetry\Instrumentation\ViewInstrumentation;
use Cbox\Telemetry\Testing\CollectingExporter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);

    View::addLocation(__DIR__.'/../fixtures/views');
    (new ViewInstrumentation)->register(app());
});

function viewSpans(CollectingExporter $collector): array
{
    return collect($collector->batches())
        ->flatMap(fn ($batch) => $batch->spans)
        ->filter(fn ($span) => str_starts_with($span->name, 'view '))
        ->values()
        ->all();
}

it('records nested render spans for views and partials', function () {
    Route::get('/page', fn () => view('page', ['title' => 'Hello']));

    $this->get('/page')->assertOk()->assertSee('Hello');

    $spans = viewSpans($this->collector);

    expect($spans)->toHaveCount(2);

    $page = collect($spans)->firstWhere('name', 'view page');
    $partial = collect($spans)->firstWhere('name', 'view partials.card');

    // The partial nests INSIDE the page's span, and both are detail-marked.
    expect($partial->parentSpanId)->toBe($page->spanId)
        ->and($page->attributes()['view.name'])->toBe('page')
        ->and($page->isDetail())->toBeTrue()
        ->and($page->durationMs())->toBeGreaterThanOrEqual($partial->durationMs());

    // The root span tallies renders even when detail spans get trimmed.
    $root = collect($this->collector->batches())->flatMap(fn ($batch) => $batch->spans)
        ->first(fn ($span) => $span->parentSpanId === null);

    expect($root->attributes()['view.render.count'])->toBe(2);
});

it('drops view spans from healthy fast traces in tail mode but keeps the tally', function () {
    config()->set('telemetry.traces.details.mode', 'tail');
    $this->refreshApplication();
    config()->set('telemetry.traces.details.mode', 'tail');
    Telemetry::addExporter($collector = new CollectingExporter);
    View::addLocation(__DIR__.'/../fixtures/views');
    (new ViewInstrumentation)->register(app());

    Route::get('/page', fn () => view('page', ['title' => 'Hej']));

    $this->get('/page')->assertOk();

    $spans = collect($collector->batches())->flatMap(fn ($batch) => $batch->spans);

    expect($spans->first(fn ($span) => str_starts_with($span->name, 'view ')))->toBeNull()
        ->and($spans->first(fn ($span) => $span->parentSpanId === null)->attributes()['view.render.count'])->toBe(2);
})->skip(fn () => ! method_exists(app(), 'flush'), 'refresh unsupported');

it('still renders when telemetry is a no-op', function () {
    config()->set('telemetry.enabled', false);

    expect(view('page', ['title' => 'Plain'])->render())->toContain('Plain');
});

it('exposes the wrapped engine so the blade error renderer can read lastCompiled', function () {
    (new ViewInstrumentation)->register(app());

    $engine = app('view')->getEngineResolver()->resolve('blade');

    expect($engine)->toBeInstanceOf(TracingEngine::class);

    // Reproduces Illuminate\Foundation\Exceptions\Renderer\Mappers\BladeMapper
    // ::getKnownPaths(): a wrapped engine without its own lastCompiled must
    // expose the inner engine through a property named exactly "engine", or
    // rendering any view exception fatals with "Property
    // TracingEngine::$lastCompiled does not exist".
    $reflection = new ReflectionClass($engine);

    expect($reflection->hasProperty('lastCompiled'))->toBeFalse()
        ->and($reflection->hasProperty('engine'))->toBeTrue();

    $inner = (new ReflectionProperty($engine, 'engine'))->getValue($engine);

    expect(new ReflectionProperty($inner, 'lastCompiled'))
        ->getValue($inner)->toBeArray();
});
