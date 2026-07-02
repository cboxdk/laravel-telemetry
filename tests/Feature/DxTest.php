<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('propagates the traceparent on outbound http requests via the macro', function () {
    Http::fake(['example.com/*' => Http::response(['ok' => true])]);

    $span = Telemetry::span('outbound.work');

    Http::withTraceparent()->post('https://example.com/api', ['x' => 1]);

    Http::assertSent(function (Request $request) use ($span) {
        return $request->hasHeader('traceparent')
            && str_contains($request->header('traceparent')[0], $span->traceId)
            && str_contains($request->header('traceparent')[0], $span->spanId);
    });

    $span->end();
});

it('sends no traceparent header when no trace is active', function () {
    Http::fake(['example.com/*' => Http::response(['ok' => true])]);

    Http::withTraceparent()->get('https://example.com/api');

    Http::assertSent(fn (Request $request) => ! $request->hasHeader('traceparent'));
});

it('reports its configuration in artisan about', function () {
    $this->artisan('about')
        ->expectsOutputToContain('Telemetry')
        ->assertSuccessful();
});
