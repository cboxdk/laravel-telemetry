<?php

declare(strict_types=1);

use Cbox\Telemetry\Exporters\NullExporter;
use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Instrumentation\HttpClientSpanMiddleware;
use Cbox\Telemetry\Instrumentation\QueryInstrumentation;
use Cbox\Telemetry\Instrumentation\RedisInstrumentation;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Event;

/**
 * The per-operation costs, which the request benchmark cannot separate.
 *
 *     vendor/bin/pest --group=benchmark
 *
 * A request pays the middleware once. It pays these once per query, per
 * Redis command, per cache operation and per outgoing hop — so an
 * application making two hundred Redis calls in a request is where a
 * number that looks small stops being small. Each case measures the
 * SAME work with the listener armed and with it absent, and reports the
 * difference, because the absolute figure is this machine's and the
 * difference is the package's.
 */
uses()->group('benchmark');

/**
 * @param  Closure(): void  $work
 * @return float microseconds per iteration
 */
function perOperationMicros(Closure $work, int $iterations = 20_000, int $warmup = 2_000): float
{
    for ($i = 0; $i < $warmup; $i++) {
        $work();
    }

    $start = hrtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        $work();
    }

    return (hrtime(true) - $start) / 1_000 / $iterations;
}

function reportDelta(string $label, float $off, float $on): void
{
    fwrite(STDERR, sprintf(
        "\n[benchmark] %-34s off=%6.2fµs on=%6.2fµs delta=%+6.2fµs  (%s)\n",
        $label,
        $off,
        $on,
        $on - $off,
        $off > 0 ? sprintf('%+.0f%%', ($on - $off) / $off * 100) : 'n/a',
    ));
}

it('measures the cost of hearing about a Redis command', function () {
    // The one made default-on: `instrument.redis_failures` needs Laravel
    // to dispatch CommandExecuted AND CommandFailed from the same switch,
    // so an application pays one event dispatch per Redis command even
    // when nothing ever fails. This is that dispatch, plus a listener
    // that does one in_array and returns.
    Telemetry::fake();

    $connection = Mockery::mock(Illuminate\Redis\Connections\Connection::class);
    $connection->shouldReceive('getName')->andReturn('cache');

    $event = new CommandExecuted('get', ['sessions:9'], 0.4, $connection);
    $dispatch = fn () => Event::dispatch($event);

    // Two costs, and only one of them is the listener.
    //
    // With neither switch on, Laravel never gives the connection a
    // dispatcher, so `Connection::command()` dispatches NOTHING and the
    // whole figure below is what asking for failures costs. With the
    // listener armed on top, the extra is what our handler costs.
    Event::forget(CommandExecuted::class);
    $dispatchOnly = perOperationMicros($dispatch);

    (new RedisInstrumentation(app()))
        ->register(app('events'), [], commands: false, failures: true);

    $withListener = perOperationMicros($dispatch);

    fwrite(STDERR, sprintf(
        "\n[benchmark] %-34s dispatch=%.2fµs +listener=%+.2fµs — the whole figure is the price of\n%50sfailure visibility, because without it nothing is dispatched at all\n",
        'redis command (failures only)',
        $dispatchOnly,
        $withListener - $dispatchOnly,
        '',
    ));

    expect($withListener)->toBeFloat();
});

it('measures the cost of a query event', function () {
    Telemetry::fake();
    Telemetry::addExporter(new NullExporter);

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getName')->andReturn('mysql');
    $connection->shouldReceive('getDriverName')->andReturn('mysql');

    $event = new QueryExecuted('select * from orders where id = ?', [1], 0.8, $connection);
    $dispatch = fn () => Event::dispatch($event);

    Event::forget(QueryExecuted::class);
    $off = perOperationMicros($dispatch, 10_000, 1_000);

    (new QueryInstrumentation(app()))->register(app('events'));

    // Outside a span: the early return, which is what an unsampled or
    // untraced request pays.
    $on = perOperationMicros($dispatch, 10_000, 1_000);
    reportDelta('query, outside a trace', $off, $on);

    // Inside a sampled span: the full path — tallies, counter, span.
    $root = Telemetry::tracer()->startSpan('checkout');
    $inside = perOperationMicros($dispatch, 10_000, 1_000);
    $root->end();

    reportDelta('query, inside a sampled trace', $off, $inside);

    // Split it: with a noise floor above this query's duration the span
    // is skipped and only the tallies and the counter remain. The
    // difference between the two lines is what a per-query span costs,
    // which is the knob (`traces.queries.min_duration_ms`) an
    // N+1-heavy application actually has.
    Event::forget(QueryExecuted::class);
    (new QueryInstrumentation(app()))->register(app('events'), minDurationMs: 1000.0);

    $root = Telemetry::tracer()->startSpan('checkout');
    $countersOnly = perOperationMicros($dispatch, 10_000, 1_000);
    $root->end();

    reportDelta('query, counters only (span skipped)', $off, $countersOnly);

    // And without N+1 detection, which is on by default and hashes every
    // statement.
    Event::forget(QueryExecuted::class);
    (new QueryInstrumentation(app()))->register(app('events'), minDurationMs: 1000.0, detectDuplicates: false);

    $root = Telemetry::tracer()->startSpan('checkout');
    $noDuplicates = perOperationMicros($dispatch, 10_000, 1_000);
    $root->end();

    reportDelta('query, no duplicate detection', $off, $noDuplicates);

    expect($on)->toBeFloat();
});

it('measures the cost of the outgoing HTTP middleware', function () {
    // The middleware, isolated from Laravel's PendingRequest and
    // Guzzle's own stack — both of which the application pays either
    // way. This is one hop through our wrapper and nothing else.
    Telemetry::fake();

    $inner = static fn ($request, array $options) => Create::promiseFor(new PsrResponse(200, [], 'ok'));
    $wrapped = (new HttpClientSpanMiddleware(app()))($inner);

    $request = new Request('GET', 'https://api.test/thing');

    $root = Telemetry::tracer()->startSpan('checkout');

    $off = perOperationMicros(fn () => $inner($request, [])->wait(), 5_000, 500);
    $on = perOperationMicros(fn () => $wrapped($request, [])->wait(), 5_000, 500);

    $root->end();

    reportDelta('outgoing http hop', $off, $on);

    expect($on)->toBeFloat();
});

it('counts what is listening on defaults', function () {
    // Cheap and worth knowing: how many of the application's own events
    // this package has an opinion about when nobody configured anything.
    app('queue');

    $ours = 0;

    foreach (app('events')->getRawListeners() as $event => $listeners) {
        foreach ((array) $listeners as $listener) {
            if (! $listener instanceof Closure) {
                continue;
            }

            $file = (new ReflectionFunction($listener))->getFileName();

            if (is_string($file) && str_contains($file, '/laravel-telemetry/src/')) {
                $ours++;
            }
        }
    }

    fwrite(STDERR, sprintf("\n[benchmark] %-34s %d listeners on default config\n", 'registered listeners', $ours));

    expect($ours)->toBeGreaterThan(0);
});
