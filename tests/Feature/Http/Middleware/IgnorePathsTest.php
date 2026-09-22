<?php

declare(strict_types=1);

use Cbox\Telemetry\Events\TelemetryEvent;
use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\SpanStatus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);

    config()->set('telemetry.analytics.enabled', true);
    config()->set('telemetry.analytics.session.salt', 'test-salt');

    $html = fn () => response('<html>ok</html>')->header('Content-Type', 'text/html');

    // The shape of the motivating case: a dashboard SPA + JSON API mounted
    // under a prefix, next to the host's own pages.
    Route::get('/telemetry-ui', $html);
    Route::get('/telemetry-ui/api/v2/panels/{panel}', fn (string $panel) => ['panel' => $panel]);
    Route::get('/dashboard', $html);
});

/** @return list<Span> */
function ignoredPathSpans(CollectingExporter $collector): array
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

/** @return list<TelemetryEvent> */
function ignoredPathEvents(CollectingExporter $collector, string $name): array
{
    Telemetry::flush();

    $events = [];

    foreach ($collector->batches() as $batch) {
        foreach ($batch->events as $event) {
            if ($event->name === $name) {
                $events[] = $event;
            }
        }
    }

    return $events;
}

/** @return list<string> the http.route label of every recorded request-duration series */
function ignoredPathRequestRoutes(): array
{
    $family = collect(Telemetry::collect())->firstWhere(fn ($family) => $family->name() === 'http.server.request.duration');

    return $family === null ? [] : collect($family->samples)->map(fn ($sample) => $sample->labels['http.route'])->all();
}

it('records no span, no request metric and no page view for a configured path', function () {
    config()->set('telemetry.instrument.http_ignore_paths', ['telemetry-ui', 'telemetry-ui/*']);

    $this->get('/telemetry-ui')->assertOk()->assertHeaderMissing('X-Trace-Id');
    $this->get('/telemetry-ui/api/v2/panels/latency')->assertOk()->assertHeaderMissing('X-Trace-Id');

    expect(ignoredPathSpans($this->collector))->toBe([])
        ->and(ignoredPathEvents($this->collector, 'analytics.page_view'))->toBe([])
        ->and(ignoredPathRequestRoutes())->toBe([])
        ->and(collect(Telemetry::collect())->map->name()->all())
        ->not->toContain('http.server.memory.peak', 'http.server.cpu.time');
});

it('still instruments every path that is not ignored', function () {
    config()->set('telemetry.instrument.http_ignore_paths', ['telemetry-ui', 'telemetry-ui/*']);

    $this->get('/telemetry-ui/api/v2/panels/latency')->assertOk();
    $this->get('/dashboard')->assertOk()->assertHeader('X-Trace-Id');

    $servers = array_values(array_filter(ignoredPathSpans($this->collector), fn (Span $span) => $span->kind === SpanKind::Server));

    expect($servers)->toHaveCount(1)
        ->and($servers[0]->name)->toBe('GET /dashboard')
        ->and(ignoredPathRequestRoutes())->toBe(['/dashboard'])
        ->and(ignoredPathEvents($this->collector, 'analytics.page_view'))->toHaveCount(1)
        ->and(ignoredPathEvents($this->collector, 'analytics.page_view')[0]->attributes['url.path'])->toBe('/dashboard');
});

it('lets packages register paths programmatically, merged with config', function () {
    config()->set('telemetry.instrument.http_ignore_paths', ['health']);
    Route::get('/health', fn () => 'ok');

    Telemetry::ignorePaths(['telemetry-ui', 'telemetry-ui/*']);
    Telemetry::ignorePaths('telemetry-ui'); // registering twice is harmless

    expect(Telemetry::ignoredPaths())->toBe(['health', 'telemetry-ui', 'telemetry-ui/*']);

    $this->get('/health')->assertOk();
    $this->get('/telemetry-ui/api/v2/panels/errors')->assertOk();
    $this->get('/dashboard')->assertOk();

    expect(collect(ignoredPathSpans($this->collector))->where('kind', SpanKind::Server)->pluck('name')->all())
        ->toBe(['GET /dashboard']);
});

it('picks up a config change made after the list was first read', function () {
    $telemetry = app(TelemetryManager::class);

    expect($telemetry->ignoresPath('health'))->toBeFalse();

    config()->set('telemetry.instrument.http_ignore_paths', ['health']);

    expect($telemetry->ignoresPath('health'))->toBeTrue();
});

it('matches Str::is globs against the path without its leading slash', function (array $patterns, string $path, bool $ignored) {
    config()->set('telemetry.instrument.http_ignore_paths', $patterns);

    expect(app(TelemetryManager::class)->ignoresPath($path))->toBe($ignored);
})->with([
    'prefix glob matches a subpath' => [['telemetry-ui/*'], 'telemetry-ui/api/v2/explore', true],
    'prefix glob does not match the bare prefix' => [['telemetry-ui/*'], 'telemetry-ui', false],
    'exact path' => [['health'], 'health', true],
    'exact path is not a prefix' => [['health'], 'healthz', false],
    'trailing star covers both' => [['horizon*'], 'horizon', true],
    'trailing star covers subpaths' => [['horizon*'], 'horizon/api/stats', true],
    'leading slash in the pattern is tolerated' => [['/health'], 'health', true],
    'trailing slash in the pattern is tolerated' => [['health/'], 'health', true],
    'root pattern matches the root' => [['/'], '/', true],
    'root pattern matches nothing else' => [['/'], 'dashboard', false],
    'blank entries are ignored' => [['', '  '], '/', false],
    'no patterns, nothing ignored' => [[], 'anything', false],
    'unrelated path' => [['telemetry-ui', 'telemetry-ui/*'], 'dashboard', false],
]);

it('reads a comma-separated env var', function () {
    putenv('TELEMETRY_HTTP_IGNORE_PATHS=telemetry-ui, telemetry-ui/* ,,horizon*');

    try {
        $config = require __DIR__.'/../../../../config/telemetry.php';
    } finally {
        putenv('TELEMETRY_HTTP_IGNORE_PATHS');
    }

    expect($config['instrument']['http_ignore_paths'])->toBe(['telemetry-ui', 'telemetry-ui/*', 'horizon*']);
});

it('defaults to ignoring nothing', function () {
    $config = require __DIR__.'/../../../../config/telemetry.php';

    expect($config['instrument']['http_ignore_paths'])->toBe([]);
});

it('keeps work inside an ignored request out of tracing — no orphan roots, no propagation', function () {
    config()->set('telemetry.instrument.http_ignore_paths', ['telemetry-ui/*']);
    Http::fake(['tempo.test/*' => Http::response(['traces' => []], 200)]);

    $seen = [];

    Route::get('/telemetry-ui/api/v2/traces', function () use (&$seen) {
        // A child span, an outgoing call (which instruments with or
        // without a parent), and a FAILING child span — the one the
        // always_sample_errors escape would otherwise export.
        Telemetry::span('panel.query', fn () => Http::withTraceparent()->get('https://tempo.test/api/search'));

        try {
            Telemetry::span('panel.failing', fn () => throw new RuntimeException('backend down'));
        } catch (RuntimeException) {
        }

        $seen = [
            'traceId' => Telemetry::traceId(),
            'traceparent' => Telemetry::traceparent(),
            'currentSpan' => Telemetry::currentSpan(),
        ];

        return ['traces' => []];
    });

    $this->get('/telemetry-ui/api/v2/traces')->assertOk()->assertHeaderMissing('X-Trace-Id');

    expect(ignoredPathSpans($this->collector))->toBe([])
        ->and($seen)->toBe(['traceId' => null, 'traceparent' => null, 'currentSpan' => null]);

    Http::assertSent(fn ($request) => ! $request->hasHeader('traceparent'));
});

it('lifts the suppression once the ignored request ends', function () {
    config()->set('telemetry.instrument.http_ignore_paths', ['telemetry-ui/*']);

    $this->get('/telemetry-ui/api/v2/panels/latency')->assertOk();

    expect(app(TelemetryManager::class)->tracer()->suppressed())->toBeFalse();

    $this->get('/dashboard')->assertOk();

    $spans = ignoredPathSpans($this->collector);

    expect(collect($spans)->where('kind', SpanKind::Server)->pluck('name')->all())->toBe(['GET /dashboard']);
});

it('still records exceptions thrown in an ignored request, without a trace id', function () {
    config()->set('telemetry.instrument.http_ignore_paths', ['telemetry-ui/*']);
    Route::get('/telemetry-ui/api/v2/broken', fn () => throw new RuntimeException('dashboard exploded'));

    $this->get('/telemetry-ui/api/v2/broken')->assertStatus(500);

    $exceptions = ignoredPathEvents($this->collector, 'exception');

    expect($exceptions)->toHaveCount(1)
        ->and($exceptions[0]->attributes['exception.type'])->toBe(RuntimeException::class)
        ->and($exceptions[0]->attributes['exception.message'])->toBe('dashboard exploded')
        ->and($exceptions[0]->traceId)->toBeNull()
        ->and($exceptions[0]->spanId)->toBeNull()
        ->and(ignoredPathSpans($this->collector))->toBe([])
        ->and(ignoredPathRequestRoutes())->toBe([]);

    $reported = collect(Telemetry::collect())->firstWhere(fn ($family) => $family->name() === 'exceptions.reported');

    expect($reported->samples[0]->labels['exception'])->toBe(RuntimeException::class);
});

it('still reports a 500 from a non-ignored request as an error span', function () {
    config()->set('telemetry.instrument.http_ignore_paths', ['telemetry-ui/*']);
    Route::get('/broken', fn () => throw new RuntimeException('real failure'));

    $this->get('/broken')->assertStatus(500);

    $server = collect(ignoredPathSpans($this->collector))->firstWhere('kind', SpanKind::Server);

    expect($server->status())->toBe(SpanStatus::Error)
        ->and(ignoredPathEvents($this->collector, 'exception')[0]->traceId)->toBe($server->traceId);
});
