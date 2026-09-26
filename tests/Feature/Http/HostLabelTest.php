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
    // Validated AND finite. Trusted hosts alone only prove the first:
    // see the wildcard case below, which is Laravel's own default.
    config()->set('app.url', 'https://app.example');
    Request::setTrustedHosts(['^(a|b)\.tenant\.example$']);

    $request = Request::create('https://b.tenant.example/x');

    expect(boundedHostFor($request))->toBe('b.tenant.example');
});

it('refuses a wildcard trusted host, which is what Laravel configures by default', function () {
    // TrustHosts::allSubdomainsOfApplicationUrl() is the documented
    // setup and the default of Laravel's own middleware. It validates
    // the Host header, which is what it is for — but an app behind
    // wildcard DNS accepts every subdomain anyone asks for, and each
    // one of them was a permanent series on three histograms.
    config()->set('app.url', 'https://app.example');
    Request::setTrustedHosts(['^(.+\.)?app\.example$']);

    $request = Request::create('https://app.example/x');
    $request->headers->set('Host', 'a94f2.app.example');
    $request->server->set('HTTP_HOST', 'a94f2.app.example');

    expect(boundedHostFor($request))->toBe('other');
});

it('keeps each host of a finite alternation', function () {
    config()->set('app.url', 'https://app.example');
    Request::setTrustedHosts(['^(a|b)\.tenant\.example$']);

    foreach (['a.tenant.example', 'b.tenant.example'] as $host) {
        $request = Request::create("https://{$host}/x");

        expect(boundedHostFor($request))->toBe($host);
    }
});

it('keeps a host the application listed itself', function () {
    // The way out for a multi-domain app that cannot enumerate its
    // domains in TrustHosts: name them here, where the list is the
    // application's and not the caller's.
    config()->set('app.url', 'https://app.example');
    config()->set('telemetry.instrument.hosts', ['shop.example', 'admin.example']);

    $request = Request::create('https://shop.example/x');

    expect(boundedHostFor($request))->toBe('shop.example');
});

it('does not read an alternation big enough to be a cardinality problem itself', function () {
    config()->set('app.url', 'https://app.example');
    Request::setTrustedHosts(['^('.implode('|', array_map(fn (int $i) => "t{$i}", range(1, 100))).')\.tenant\.example$']);

    $request = Request::create('https://t7.tenant.example/x');

    expect(boundedHostFor($request))->toBe('other');
});
