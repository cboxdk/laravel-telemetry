<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Support\FailSafe;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Predis\Client;

/**
 * The paths that are not events: middleware the application's own
 * requests and outgoing calls pass through, and a metric store that has
 * gone away underneath them.
 *
 * These are worse than a listener when they fail. A listener that throws
 * loses one signal; middleware that throws loses the response.
 */
it('serves the request when the metric store is gone', function (): void {
    // Not a fake failure: a real store whose backend refuses everything,
    // which is what a Redis outage looks like from in here.
    config()->set('telemetry.store', 'redis');
    config()->set('database.redis.default', ['host' => '127.0.0.1', 'port' => 1, 'database' => 0]);

    Route::middleware('web')->get('/orders', fn () => 'ok');

    $caught = [];
    FailSafe::handleExceptionsUsing(static function (Throwable $e) use (&$caught): void {
        $caught[] = $e;
    });

    try {
        $this->get('/orders')->assertOk()->assertSee('ok');
    } finally {
        FailSafe::handleExceptionsUsing(null);
    }
})->skip(fn (): bool => ! extension_loaded('redis') && ! class_exists(Client::class), 'needs a redis client to fail against');

it('returns the response even when recording the call throws', function (): void {
    // The middleware sits inside every outgoing Guzzle call. If it can
    // turn a successful upstream response into an exception, every
    // integration in the application is one telemetry bug away from
    // breaking.
    Telemetry::fake();

    $http = Http::setHandler(function ($request, array $options) {
        if (isset($options['on_stats'])) {
            // Malformed stats: a handler that reports nonsense must not
            // reach the histogram, let alone the caller.
            $options['on_stats'](new TransferStats($request, null, 0.1, null, [
                'total_time_us' => 'not a number',
                'connect_time_us' => [],
                'http_version' => 'two',
            ]));
        }

        return Create::promiseFor(new PsrResponse(200, [], 'upstream says yes'));
    });

    $response = $http->get('https://api.test/thing');

    expect($response->status())->toBe(200)
        ->and($response->body())->toBe('upstream says yes');

    // Nonsense in, nothing out — not a zero, and not an exception.
    Telemetry::assertHistogramNotRecorded('http.client.connection.duration');
});

it('lets a connection failure reach the caller unchanged', function (): void {
    // The one thing worse than losing telemetry is swallowing the
    // application's own error. A refused connection must still be a
    // refused connection.
    Telemetry::fake();

    $http = Http::setHandler(fn ($request) => Create::rejectionFor(
        new ConnectException('refused', new GuzzleRequest('GET', 'https://down.test')),
    ));

    expect(fn () => $http->get('https://down.test/thing'))
        ->toThrow(ConnectionException::class);
});

it('closes the client span when the promise is rejected, so nothing is left ambient', function (): void {
    Telemetry::fake();

    $http = Http::setHandler(fn ($request) => Create::rejectionFor(
        new ConnectException('refused', new GuzzleRequest('GET', 'https://down.test')),
    ));

    $root = Telemetry::tracer()->startSpan('checkout');

    try {
        $http->get('https://down.test/thing');
    } catch (Throwable) {
        // The caller's problem.
    }

    // A client span left open would stay on the stack and adopt
    // everything the request did next.
    expect(Telemetry::currentSpan()?->name)->toBe('checkout');

    $root->end();
});
