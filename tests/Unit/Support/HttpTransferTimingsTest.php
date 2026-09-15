<?php

declare(strict_types=1);

use Cbox\Telemetry\Support\HttpTransferTimings;

// The shapes below are taken from real transfers, not invented: a cold HTTPS
// request, the second request over the same connection, and plain HTTP.

it('breaks a cold request into its phases', function () {
    $attributes = HttpTransferTimings::attributes([
        'namelookup_time_us' => 3458,
        'connect_time_us' => 11094,
        'appconnect_time_us' => 30540,
        'pretransfer_time_us' => 30619,
        'starttransfer_time_us' => 47330,
        'total_time_us' => 48253,
        'primary_ip' => '172.66.147.243',
        'primary_port' => 443,
        'http_version' => 3,
    ]);

    expect($attributes)->toBe([
        'network.peer.address' => '172.66.147.243',
        'network.peer.port' => 443,
        // CURL_HTTP_VERSION_2_0 is the integer 3, not 2.
        'network.protocol.version' => '2',
        'http.client.connection_reused' => false,
        'http.client.dns_ms' => 3.458,
        'http.client.tcp_ms' => 7.636,
        'http.client.tls_ms' => 19.446,
        'http.client.ttfb_ms' => 16.711,
        'http.client.transfer_ms' => 0.923,
    ]);
});

it('reports a reused connection as an absence, not as instant DNS', function () {
    // cURL leaves lookup, connect and handshake at exactly 0 when it reused a
    // connection. Reporting those as 0ms phases would read as a DNS lookup
    // that took no time, which is a different and much more interesting claim
    // than the truth.
    $attributes = HttpTransferTimings::attributes([
        'namelookup_time_us' => 0,
        'connect_time_us' => 0,
        'appconnect_time_us' => 0,
        'pretransfer_time_us' => 75,
        'starttransfer_time_us' => 17934,
        'total_time_us' => 18003,
    ]);

    expect($attributes)->toBe([
        'http.client.connection_reused' => true,
        'http.client.ttfb_ms' => 17.859,
        'http.client.transfer_ms' => 0.069,
    ]);
});

it('does not invent a TLS handshake for plain HTTP', function () {
    // appconnect is 0 here too, but connect is not — so this is a new
    // connection that simply had no handshake to do.
    $attributes = HttpTransferTimings::attributes([
        'namelookup_time_us' => 34845,
        'connect_time_us' => 4224779,
        'appconnect_time_us' => 0,
        'pretransfer_time_us' => 4224903,
        'starttransfer_time_us' => 4411291,
        'total_time_us' => 4413151,
    ]);

    expect($attributes)->toHaveKey('http.client.tcp_ms')
        ->and($attributes)->not->toHaveKey('http.client.tls_ms')
        ->and($attributes['http.client.connection_reused'])->toBeFalse();
});

it('reports nothing at all when the handler is not cURL', function () {
    // The stream handler, and a faked response — which carries a TransferStats
    // with no handler stats behind it. Zeroes would have to be explained.
    expect(HttpTransferTimings::attributes([]))->toBe([]);
});

it('reports the peer without inventing phases from partial stats', function () {
    // Knowing WHICH address answered is half the story on a slow call, and a
    // build too old for one set of keys still has the other. Absent connect
    // time means the stats are partial — guessing 0 there would report a fresh
    // connection as a reused one.
    expect(HttpTransferTimings::attributes(['primary_ip' => '1.2.3.4', 'total_time_us' => 1000]))
        ->toBe(['network.peer.address' => '1.2.3.4']);
});

it('falls back to the seconds keys when libcurl is too old for microseconds', function () {
    // PHP populates the `_us` keys only when built against libcurl 7.61+, and
    // PHP 8.3 still builds against 7.29. Losing precision beats losing every
    // attribute on those installations.
    $attributes = HttpTransferTimings::attributes([
        'namelookup_time' => 0.003458,
        'connect_time' => 0.011094,
        'appconnect_time' => 0.030540,
        'pretransfer_time' => 0.030619,
        'starttransfer_time' => 0.047330,
        'total_time' => 0.048253,
    ]);

    expect($attributes['http.client.dns_ms'])->toBe(3.458)
        ->and($attributes['http.client.tcp_ms'])->toBe(7.636)
        ->and($attributes['http.client.tls_ms'])->toBe(19.446);
});

it('does not split a QUIC connection into TCP and TLS', function () {
    // HTTP/3 has no TCP handshake to time — cURL's connect timestamp for QUIC
    // marks the first data from the peer — so the split would be fiction. One
    // number covers the setup instead.
    $attributes = HttpTransferTimings::attributes([
        'namelookup_time_us' => 3458,
        'connect_time_us' => 11094,
        'appconnect_time_us' => 30540,
        'pretransfer_time_us' => 30619,
        'starttransfer_time_us' => 47330,
        'total_time_us' => 48253,
        'http_version' => 30,
    ]);

    expect($attributes['network.protocol.version'])->toBe('3')
        ->and($attributes['http.client.connect_ms'])->toBe(27.082)
        ->and($attributes)->not->toHaveKey('http.client.tcp_ms')
        ->and($attributes)->not->toHaveKey('http.client.tls_ms');
});

it('never reports a negative phase', function () {
    // cURL leaves a phase it never reached at 0, so a difference can come out
    // below zero. A phase cannot take less than no time.
    $attributes = HttpTransferTimings::attributes([
        'namelookup_time_us' => 5000,
        'connect_time_us' => 4000,
        'appconnect_time_us' => 0,
        'pretransfer_time_us' => 9000,
        'starttransfer_time_us' => 1000,
        'total_time_us' => 500,
    ]);

    expect($attributes['http.client.tcp_ms'])->toBe(0.0)
        ->and($attributes['http.client.ttfb_ms'])->toBe(0.0)
        ->and($attributes['http.client.transfer_ms'])->toBe(0.0);
});

it('spells the protocol version the way semconv does', function () {
    foreach ([1 => '1.0', 2 => '1.1', 3 => '2', 30 => '3'] as $curl => $expected) {
        $attributes = HttpTransferTimings::attributes(['total_time_us' => 10, 'connect_time_us' => 5, 'http_version' => $curl]);

        expect($attributes['network.protocol.version'])->toBe($expected);
    }

    // An unknown constant says nothing rather than guessing.
    expect(HttpTransferTimings::attributes(['total_time_us' => 10, 'http_version' => 99]))
        ->not->toHaveKey('network.protocol.version');
});
