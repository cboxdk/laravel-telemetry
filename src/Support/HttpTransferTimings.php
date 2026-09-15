<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

/**
 * The phases inside an outgoing HTTP request, from cURL's own transfer stats.
 *
 * A client span says a call took 284ms. It does not say whether that was DNS,
 * connection setup, waiting on the far end, or the response coming back down
 * — and those have entirely different fixes. cURL measures it already, and
 * Laravel's HTTP client keeps it: `PendingRequest` installs its own `on_stats`
 * callback and hands the resulting `TransferStats` to the response, so
 * `$response->handlerStats()` is populated on every real cURL request with no
 * configuration and no extension.
 *
 * The raw values are CUMULATIVE offsets from the start of the transfer, so
 * each phase is a difference.
 *
 * WHAT THESE INTERVALS ARE, AND ARE NOT
 *
 * `ttfb_ms` is `pretransfer` to `starttransfer`. That is not purely the server
 * thinking:
 *
 *  - It INCLUDES sending the request body. `pretransfer` means cURL is about
 *    to transmit, not that it has finished, so a large upload lands here.
 *  - It ENDS at the first response headers cURL processes, which for a body
 *    large enough that Guzzle adds `Expect: 100-continue` is the interim
 *    `100 Continue` — not the real response. The upload and the server's work
 *    then fall into `transfer_ms` instead.
 *
 * `tls_ms` is `connect` to `appconnect`. Through an HTTP CONNECT proxy that
 * interval also contains the proxy tunnel negotiation, which cURL completes
 * before starting TLS to the origin. A slow proxy reads as slow TLS.
 *
 * Over HTTP/3 there is no TCP handshake to time — cURL's connect timestamp for
 * QUIC marks the first data from the peer — so the split into `tcp_ms` and
 * `tls_ms` would be fiction. One `connect_ms` covers the whole setup there.
 *
 * A REUSED connection reports a connect time of exactly 0: no connection was
 * made. The connection phases are then omitted rather than reported as zeroes,
 * because "DNS took 0ms" is a different and much more interesting claim than
 * "there was no lookup". (cURL does still run the name-lookup timer on a
 * reused connection, so `connect` is the reliable signal, not `namelookup`.)
 *
 * Guzzle follows redirects itself, so these phases describe the LAST hop while
 * the span covers them all — they need not add up to it.
 */
final class HttpTransferTimings
{
    /**
     * cURL's `CURLINFO_HTTP_VERSION` constants, as `network.protocol.version`
     * spells them. Not the wire name: 2 is CURL_HTTP_VERSION_1_1, 3 is
     * CURL_HTTP_VERSION_2_0.
     */
    private const PROTOCOL_VERSIONS = [
        1 => '1.0',
        2 => '1.1',
        3 => '2',
        30 => '3',
    ];

    /** CURL_HTTP_VERSION_3 — QUIC, so no TCP handshake of its own. */
    private const HTTP_3 = 30;

    /**
     * @param  array<string, mixed>  $stats  `$response->handlerStats()`
     * @return array<string, scalar|null>
     */
    public static function attributes(array $stats): array
    {
        // Peer facts are read independently of the timings. A build whose
        // libcurl is too old for one still has the other, and knowing WHICH
        // address answered is half the story on a slow call.
        return self::peer($stats) + self::phases($stats);
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return array<string, scalar|null>
     */
    private static function phases(array $stats): array
    {
        $total = self::micros($stats, 'total_time');
        $connect = self::micros($stats, 'connect_time');

        // No total means this was not a cURL transfer at all: the stream
        // handler, or a faked response carrying a TransferStats with nothing
        // behind it. No connect means the stats are partial, and guessing 0
        // there would report a fresh connection as a reused one.
        if ($total === null || $connect === null) {
            return [];
        }

        $namelookup = self::micros($stats, 'namelookup_time') ?? 0;
        $appconnect = self::micros($stats, 'appconnect_time') ?? 0;
        $pretransfer = self::micros($stats, 'pretransfer_time') ?? 0;
        $starttransfer = self::micros($stats, 'starttransfer_time') ?? 0;

        if ($connect === 0) {
            return [
                'http.client.connection_reused' => true,
                'http.client.ttfb_ms' => self::ms($starttransfer - $pretransfer),
                'http.client.transfer_ms' => self::ms($total - $starttransfer),
            ];
        }

        $phases = [
            'http.client.connection_reused' => false,
            'http.client.dns_ms' => self::ms($namelookup),
        ];

        if (self::isQuic($stats)) {
            // QUIC does its handshake inside the transport, so the whole
            // setup is one number rather than a TCP part and a TLS part.
            $phases['http.client.connect_ms'] = self::ms(($appconnect > 0 ? $appconnect : $connect) - $namelookup);
        } else {
            $phases['http.client.tcp_ms'] = self::ms($connect - $namelookup);

            // Zero over plain HTTP, where there is no handshake to report.
            if ($appconnect > 0) {
                $phases['http.client.tls_ms'] = self::ms($appconnect - $connect);
            }
        }

        $phases['http.client.ttfb_ms'] = self::ms($starttransfer - $pretransfer);
        $phases['http.client.transfer_ms'] = self::ms($total - $starttransfer);

        return $phases;
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return array<string, scalar|null>
     */
    private static function peer(array $stats): array
    {
        $peer = [];

        $address = $stats['primary_ip'] ?? null;
        $port = $stats['primary_port'] ?? null;
        $version = $stats['http_version'] ?? null;

        if (is_string($address) && $address !== '') {
            $peer['network.peer.address'] = $address;
        }

        if (is_int($port) && $port > 0) {
            $peer['network.peer.port'] = $port;
        }

        if (is_int($version) && isset(self::PROTOCOL_VERSIONS[$version])) {
            $peer['network.protocol.version'] = self::PROTOCOL_VERSIONS[$version];
        }

        return $peer;
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private static function isQuic(array $stats): bool
    {
        return ($stats['http_version'] ?? null) === self::HTTP_3;
    }

    /**
     * Microseconds for a phase, from whichever form this build reports.
     *
     * The `_us` keys are integers and exact, but PHP only populates them when
     * it was built against libcurl 7.61 or newer — and PHP 8.3 still builds
     * against 7.29. The float seconds beside them have always been there, so
     * an older build loses precision rather than losing the attribute.
     *
     * @param  array<string, mixed>  $stats
     * @param  string  $key  Without a suffix: `total_time`, not `total_time_us`.
     */
    private static function micros(array $stats, string $key): ?int
    {
        $micros = $stats[$key.'_us'] ?? null;

        if (is_int($micros) || is_float($micros)) {
            return (int) $micros;
        }

        $seconds = $stats[$key] ?? null;

        return is_int($seconds) || is_float($seconds) ? (int) round($seconds * 1_000_000) : null;
    }

    /**
     * Microseconds to milliseconds, never negative.
     *
     * A phase cannot take less than no time. A difference below zero means the
     * two offsets were not both measured — cURL leaves a phase it never
     * reached at 0 — and a negative duration would be worse than none.
     */
    private static function ms(int $micros): float
    {
        return $micros <= 0 ? 0.0 : round($micros / 1000, 3);
    }
}
