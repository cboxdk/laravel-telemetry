<?php

declare(strict_types=1);

use Cbox\Telemetry\Http\Middleware\TraceRequest;
use Illuminate\Http\Request;

/**
 * server.address is a METRIC label. $request->getHost() is the client's Host
 * header, and Symfony validates it only when the app configured trusted-host
 * patterns — Laravel ships with none. Unbounded, an unauthenticated loop with
 * an incrementing Host mints a permanent series per value across three
 * histograms, and no store here has a TTL or a cardinality cap.
 */
function boundedHostFor(Request $request): string
{
    $method = new ReflectionMethod(TraceRequest::class, 'boundedHost');

    return $method->invoke(
        (new ReflectionClass(TraceRequest::class))->newInstanceWithoutConstructor(),
        $request,
    );
}

afterEach(function () {
    Request::setTrustedHosts([]);
});

it('collapses an unvouched-for Host into one bucket', function () {
    config()->set('app.url', 'https://app.example');

    foreach (['a1.attacker.example', 'a2.attacker.example', 'a3.attacker.example'] as $host) {
        $request = Request::create('https://app.example/x');
        $request->headers->set('Host', $host);
        $request->server->set('HTTP_HOST', $host);

        expect(boundedHostFor($request))->toBe('other');
    }
});

it('keeps the app own host, which is the single-domain case', function () {
    config()->set('app.url', 'https://app.example');

    $request = Request::create('https://app.example/x');

    expect(boundedHostFor($request))->toBe('app.example');
});

it('trusts the concrete host once trusted-host patterns exist', function () {
    // Symfony has already thrown on anything else by the time we are called,
    // so the value is validated by something the app controls.
    config()->set('app.url', 'https://app.example');
    Request::setTrustedHosts(['^(a|b)\.tenant\.example$']);

    $request = Request::create('https://b.tenant.example/x');

    expect(boundedHostFor($request))->toBe('b.tenant.example');
});
