<?php

declare(strict_types=1);

use Cbox\Telemetry\Support\CloudflareHeaders;
use Illuminate\Http\Request;

afterEach(function () {
    // Reset the Symfony-global trusted-proxy state between tests.
    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
});

function cfRequest(array $headers, string $remoteAddr = '10.0.0.1'): Request
{
    $request = Request::create('https://shop.test/x', 'GET', server: ['REMOTE_ADDR' => $remoteAddr]);

    foreach ($headers as $key => $value) {
        $request->headers->set($key, $value);
    }

    return $request;
}

it('reads CF-IPCountry only when the request is from a trusted proxy', function () {
    Request::setTrustedProxies(['10.0.0.1'], Request::HEADER_X_FORWARDED_FOR);

    $geo = CloudflareHeaders::geo(cfRequest(['CF-IPCountry' => 'DK']));

    expect($geo)->toBe(['geo.country.iso_code' => 'DK']);
});

it('ignores CF headers from an untrusted origin (spoofable)', function () {
    Request::setTrustedProxies(['10.0.0.1'], Request::HEADER_X_FORWARDED_FOR);

    // Same header, but the connection did not come through the trusted proxy.
    $geo = CloudflareHeaders::geo(cfRequest(['CF-IPCountry' => 'DK'], remoteAddr: '203.0.113.9'));

    expect($geo)->toBe([]);
});

it('is a no-op when no trusted proxies are configured', function () {
    expect(CloudflareHeaders::geo(cfRequest(['CF-IPCountry' => 'DK'])))->toBe([]);
});

it('drops the XX (unknown) and T1 (Tor) sentinels', function () {
    Request::setTrustedProxies(['10.0.0.1'], Request::HEADER_X_FORWARDED_FOR);

    expect(CloudflareHeaders::geo(cfRequest(['CF-IPCountry' => 'XX'])))->toBe([])
        ->and(CloudflareHeaders::geo(cfRequest(['CF-IPCountry' => 'T1'])))->toBe([]);
});

it('builds an ISO 3166-2 region code, and omits the region without one', function () {
    Request::setTrustedProxies(['10.0.0.1'], Request::HEADER_X_FORWARDED_FOR);

    // geo.region.iso_code is ISO 3166-2 ("US-CA"). CF-Region is the region
    // NAME; the code is in CF-Region-Code. Emitting "California" under an
    // iso_code attribute makes every region filter and geo join miss.
    $geo = CloudflareHeaders::geo(cfRequest([
        'CF-IPCountry' => 'us',
        'CF-Region' => 'California',
        'CF-Region-Code' => 'ca',
        'CF-IPCity' => 'San Francisco',
    ]));

    expect($geo)->toBe([
        'geo.country.iso_code' => 'US',   // normalized to upper-case ISO
        'geo.region.iso_code' => 'US-CA',
        'geo.locality.name' => 'San Francisco',
    ]);

    // The name alone is not a code, so it is dropped rather than mislabelled.
    $nameOnly = CloudflareHeaders::geo(cfRequest([
        'CF-IPCountry' => 'us',
        'CF-Region' => 'California',
        'CF-IPCity' => 'San Francisco',
    ]));

    expect($nameOnly)->toBe([
        'geo.country.iso_code' => 'US',
        'geo.locality.name' => 'San Francisco',
    ]);
});
