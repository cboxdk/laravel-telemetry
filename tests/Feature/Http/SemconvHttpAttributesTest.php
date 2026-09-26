<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Tracing\Span;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\TransferStats;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/**
 * The attributes semconv marks required, and what each one is actually
 * for. A conformance list is easy to treat as box-ticking; every one of
 * these answers a question the others cannot.
 */
beforeEach(function () {
    Telemetry::fake();
});

it('names the whole URL, the scheme and the port on a client span', function () {
    Http::fake(['api.example.test/*' => Http::response(['ok' => true])]);

    $root = Telemetry::tracer()->startSpan('checkout');
    Http::get('https://api.example.test/v1/charges?limit=2');
    $root->end();

    Telemetry::assertSpanRecorded('GET api.example.test', function (Span $span): bool {
        $a = $span->attributes();

        return ($a['url.full'] ?? null) === 'https://api.example.test/v1/charges?limit=2'
            && ($a['url.scheme'] ?? null) === 'https'
            && ($a['server.port'] ?? null) === 443;
    });
});

it('does not invent a status code for a call that never connected', function () {
    // `0` is not a status. A connection refused and a server answering
    // zero are different events, and only one of them is real.
    Http::fake(fn () => throw new ConnectException('refused', new GuzzleRequest('GET', 'https://down.test')));

    $root = Telemetry::tracer()->startSpan('checkout');

    try {
        Http::get('https://down.test/thing');
    } catch (Throwable) {
        // The caller's problem; we only care what was recorded.
    }

    $root->end();

    $samples = Telemetry::recordedMetrics('http.client.request.duration')->samples();

    expect($samples)->not->toBeEmpty();

    $labels = $samples[0]->labels;

    expect($labels)->not->toHaveKey('http.response.status_code')
        ->and($labels['error.type'] ?? null)->toBe(ConnectException::class);
});

it('names the scheme and the failure on the server metric', function () {
    Route::middleware('web')->get('/boom', fn () => throw new RuntimeException('no'));

    try {
        $this->get('/boom');
    } catch (Throwable) {
        // Testbench rethrows; the metric is written either way.
    }

    $samples = Telemetry::recordedMetrics('http.server.request.duration')->samples();

    expect($samples)->not->toBeEmpty();

    $labels = $samples[0]->labels;

    // semconv wants the exception class where one is known and the
    // status code otherwise. Which arrives depends on whether the
    // application's handler reported the throwable before the response
    // was built; the label is present either way, which is the point.
    expect($labels['url.scheme'] ?? null)->toBe('http')
        ->and($labels['error.type'] ?? null)->toBeIn([RuntimeException::class, '500']);
});

it('puts the exception class on the span it failed', function () {
    // The exception event carries the class name where only a human
    // reading one span will find it. `error.type` is the form a backend
    // can group and filter by, which is what turns fifty exceptions into
    // one cause.
    $span = Telemetry::tracer()->startSpan('checkout');
    $span->recordException(new RuntimeException('no'));
    $span->end();

    Telemetry::assertSpanRecorded(
        'checkout',
        fn (Span $s): bool => ($s->attributes()['error.type'] ?? null) === RuntimeException::class,
    );
});

function statsHandler(array $handlerStats): Closure
{
    return function ($request, array $options) use ($handlerStats) {
        if (isset($options['on_stats'])) {
            $options['on_stats'](new TransferStats($request, null, 0.1, null, $handlerStats));
        }

        return Create::promiseFor(new PsrResponse(200, [], 'ok'));
    };
}

it('times connection setup as its own metric', function () {
    // A call that took 300ms might have spent 290 on a handshake to a
    // machine three regions away, or 290 waiting for the far end to
    // think. Same number, opposite fixes — and only a metric can say
    // that ONE host's setup to one endpoint is twenty times everybody
    // else's, which is what a mis-provisioned zone looks like.
    Http::setHandler(statsHandler([
        'total_time_us' => 300_000,
        'namelookup_time_us' => 10_000,
        'connect_time_us' => 40_000,
        'appconnect_time_us' => 90_000,
        'pretransfer_time_us' => 95_000,
        'starttransfer_time_us' => 280_000,
        'http_version' => 3,
    ]))->get('https://slow.test/thing');

    // 10ms DNS + 30ms TCP + 50ms TLS.
    $labels = [
        'server.address' => 'slow.test',
        'url.scheme' => 'https',
        // cURL's HTTP_VERSION_2_0 constant is 3, not 2.
        'network.protocol.version' => '2',
    ];

    Telemetry::assertHistogramRecorded('http.client.connection.duration', $labels);

    // 10ms DNS + 30ms TCP + 50ms TLS = 90ms, and none of the 210ms the
    // far end spent thinking.
    expect(Telemetry::histogramSum('http.client.connection.duration', $labels))
        ->toBeGreaterThan(0.08)
        ->toBeLessThan(0.1);
});

it('does not time a connection that was reused', function () {
    // A reused connection reports a setup of exactly zero. Folding those
    // in would drag every percentile toward zero in proportion to how
    // well pooling is working — the healthier the client, the more it
    // would hide.
    Http::setHandler(statsHandler([
        'total_time_us' => 120_000,
        'connect_time_us' => 0,
        'pretransfer_time_us' => 1_000,
        'starttransfer_time_us' => 110_000,
    ]))->get('https://warm.test/thing');

    Telemetry::assertHistogramNotRecorded('http.client.connection.duration');
});
