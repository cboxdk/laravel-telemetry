<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

/**
 * The phases inside an outgoing HTTP request, from cURL's own transfer stats.
 *
 * A client span says a call took 284ms. It does not say whether that was DNS,
 * a slow TLS handshake, the server thinking, or a large body coming back down
 * — and those have entirely different fixes. cURL measures all of it already,
 * and Laravel's HTTP client already keeps it: `PendingRequest` installs its own
 * `on_stats` callback and hands the resulting `TransferStats` to the response,
 * so `$response->handlerStats()` is populated on every real request with no
 * configuration, no extra option, and no extension.
 *
 * The raw numbers are CUMULATIVE offsets from the start of the transfer, so
 * each phase is a difference. Microsecond keys are used rather than the float
 * seconds beside them: `namelookup_time_us` is an integer of microseconds,
 * where `namelookup_time` is a float that has already lost precision.
 *
 * Three things the numbers do NOT mean, all verified against a live transfer
 * rather than assumed:
 *
 *  - A REUSED connection reports namelookup, connect and appconnect as exactly
 *    0 — there was no lookup and no handshake. That is not "instantaneous DNS";
 *    it is an absence, and it is reported as one.
 *  - Plain HTTP reports appconnect as 0 with a non-zero connect. So a zero TLS
 *    time means "no handshake", and only connect tells you why.
 *  - Guzzle follows redirects with its own middleware, a fresh cURL handle per
 *    hop, so `redirect_count` is 0 and these phases describe the LAST hop only.
 *    The span's own duration covers every hop, which is why the phases need not
 *    add up to it.
 */
final class HttpTransferTimings
{
    /**
     * cURL's `CURLINFO_HTTP_VERSION` constants, as `network.protocol.version`
     * spells them. Not the wire name: 2 is CURL_HTTP_VERSION_1_1, and 3 is
     * CURL_HTTP_VERSION_2_0.
     */
    private const PROTOCOL_VERSIONS = [
        1 => '1.0',
        2 => '1.1',
        3 => '2',
        30 => '3',
    ];

    /**
     * @param  array<string, mixed>  $stats  `$response->handlerStats()`
     * @return array<string, scalar|null>
     */
    public static function attributes(array $stats): array
    {
        // Absent for every handler that is not cURL — the stream handler, and
        // a faked response, which carries a TransferStats with no handler
        // stats behind it. Nothing to report rather than zeroes to explain.
        $total = self::micros($stats, 'total_time_us');

        if ($total === null) {
            return [];
        }

        $namelookup = self::micros($stats, 'namelookup_time_us') ?? 0;
        $connect = self::micros($stats, 'connect_time_us') ?? 0;
        $appconnect = self::micros($stats, 'appconnect_time_us') ?? 0;
        $pretransfer = self::micros($stats, 'pretransfer_time_us') ?? 0;
        $starttransfer = self::micros($stats, 'starttransfer_time_us') ?? 0;

        // No connect time means no connection was made: this request went out
        // over one that was already open.
        $reused = $connect === 0;

        $attributes = ['http.client.connection_reused' => $reused];

        if (! $reused) {
            $attributes['http.client.dns_ms'] = self::ms($namelookup);
            $attributes['http.client.tcp_ms'] = self::ms($connect - $namelookup);

            if ($appconnect > 0) {
                $attributes['http.client.tls_ms'] = self::ms($appconnect - $connect);
            }
        }

        // What the far end spent thinking, measured from the moment the
        // request was on the wire rather than from the start of everything.
        $attributes['http.client.ttfb_ms'] = self::ms($starttransfer - $pretransfer);
        $attributes['http.client.transfer_ms'] = self::ms($total - $starttransfer);

        // semconv names for the peer, which is the other half of a slow call:
        // WHICH address answered, when a hostname has several.
        $address = $stats['primary_ip'] ?? null;
        $port = $stats['primary_port'] ?? null;
        $version = $stats['http_version'] ?? null;

        if (is_string($address) && $address !== '') {
            $attributes['network.peer.address'] = $address;
        }

        if (is_int($port) && $port > 0) {
            $attributes['network.peer.port'] = $port;
        }

        if (is_int($version) && isset(self::PROTOCOL_VERSIONS[$version])) {
            $attributes['network.protocol.version'] = self::PROTOCOL_VERSIONS[$version];
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private static function micros(array $stats, string $key): ?int
    {
        $value = $stats[$key] ?? null;

        return is_int($value) || is_float($value) ? (int) $value : null;
    }

    /**
     * Microseconds to milliseconds, never negative.
     *
     * A phase cannot take less than no time. A difference that comes out below
     * zero means the two offsets were not both measured — cURL leaves a phase
     * it never reached at 0 — and reporting a negative duration would be worse
     * than reporting none.
     */
    private static function ms(int $micros): float
    {
        return $micros <= 0 ? 0.0 : round($micros / 1000, 3);
    }
}
