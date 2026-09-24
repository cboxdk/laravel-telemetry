<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanKind;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;

class TelemetryActionSlowMiddleware
{
    public function handle($request, Closure $next)
    {
        usleep(30_000);

        return $next($request);
    }
}

class TelemetryActionDenyMiddleware
{
    public function handle($request, Closure $next)
    {
        return response('denied', 403);
    }
}

class TelemetryActionProbeController extends Controller
{
    public function show(): string
    {
        return 'ok';
    }

    public function __invoke(): string
    {
        return 'ok';
    }
}

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);
});

/** @return list<Span> */
function actionSpans(CollectingExporter $collector): array
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

function actionServerSpan(CollectingExporter $collector): Span
{
    $span = collect(actionSpans($collector))->firstWhere('kind', SpanKind::Server);

    assert($span instanceof Span);

    return $span;
}

it('says which controller answered, not just which route matched', function () {
    Route::get('/action', [TelemetryActionProbeController::class, 'show']);

    $this->get('/action')->assertOk();

    expect(actionServerSpan($this->collector)->attributes())
        ->toMatchArray([
            'http.route' => '/action',
            'code.namespace' => TelemetryActionProbeController::class,
            'code.function' => 'show',
        ]);
});

it('reports an invokable controller as __invoke', function () {
    Route::get('/invokable', TelemetryActionProbeController::class);

    $this->get('/invokable')->assertOk();

    expect(actionServerSpan($this->collector)->attributes())
        ->toMatchArray([
            'code.namespace' => TelemetryActionProbeController::class,
            'code.function' => '__invoke',
        ]);
});

it('does not invent a class for a closure route', function () {
    Route::get('/closure', fn () => 'ok');

    $this->get('/closure')->assertOk();

    $attributes = actionServerSpan($this->collector)->attributes();

    expect($attributes['code.function'])->toBe('Closure')
        ->and($attributes)->not->toHaveKey('code.namespace');
});

it('separates the route middleware from the controller', function () {
    Route::get('/timed', [TelemetryActionProbeController::class, 'show'])
        ->middleware(TelemetryActionSlowMiddleware::class);

    $this->get('/timed')->assertOk();

    $server = actionServerSpan($this->collector);
    $phases = collect(actionSpans($this->collector))
        ->filter(fn (Span $span) => str_starts_with($span->name, 'laravel.'));

    // The whole point: without the boundary this 30ms is indistinguishable
    // from time the controller spent, because both sat inside one phase.
    expect($phases->pluck('name')->all())->toContain('laravel.middleware')
        ->and($server->attributes()['laravel.middleware_ms'])->toBeGreaterThan(25.0)
        ->and($server->attributes()['laravel.handler_ms'])->toBeLessThan(25.0);
});

it('folds the phase into the handler when middleware answers instead', function () {
    Route::get('/short-circuit', [TelemetryActionProbeController::class, 'show'])
        ->middleware(TelemetryActionDenyMiddleware::class);

    $this->get('/short-circuit')->assertForbidden();

    $phases = collect(actionSpans($this->collector))
        ->filter(fn (Span $span) => str_starts_with($span->name, 'laravel.'));

    // Recording a middleware cost of zero would be worse than recording
    // nothing: the request never got past the middleware at all.
    expect($phases->pluck('name')->all())->not->toContain('laravel.middleware');
});
