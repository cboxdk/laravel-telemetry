<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Exporters\Otlp;

use Cbox\Telemetry\Support\Cast;
use Cbox\Telemetry\Support\ExportResult;

/**
 * Minimal OTLP/HTTP transport on raw curl — no Guzzle, no PSR stack,
 * nothing to conflict with the host application.
 *
 * Implements the OTLP spec's response classification: 429/502/503/504 and
 * network errors are retryable (honouring Retry-After); other non-2xx
 * are permanent failures.
 */
class OtlpTransport
{
    /**
     * Bodies above this many bytes are gzipped. Public because
     * `telemetry:doctor` probes with a payload big enough to cross it —
     * a diagnostic that only ever sends uncompressed bodies does not
     * test the path the exporter actually takes.
     */
    public const COMPRESSION_THRESHOLD = 1024;

    /** Characters of the backend's error body carried into the failure reason. */
    private const MAX_REPORTED_BODY = 500;

    /**
     * Bytes of the response kept.
     *
     * A collector answers with a few hundred bytes of JSON. Something
     * that is not a collector — a proxy error page, a captive portal, a
     * misconfigured endpoint pointed at a file server — can answer with
     * anything at all, and the whole of it was being buffered into the
     * request's memory. We only ever read a partial-success count or an
     * error message out of it, and 64KiB is far more than either needs.
     */
    private const MAX_RESPONSE_BYTES = 65536;

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly array $headers = [],
        private readonly float $timeout = 3.0,
        private readonly float $connectTimeout = 1.0,
        private readonly bool $compress = true,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function post(string $path, array $payload): ExportResult
    {
        $url = rtrim($this->endpoint, '/').$path;

        try {
            // INVALID_UTF8_SUBSTITUTE: one bad byte in a SQL string or
            // header must never cost the whole batch.
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $e) {
            return ExportResult::failed('payload serialization failed: '.$e->getMessage());
        }

        $headers = ['Content-Type: application/json'];

        // gzip large batches — 5000 spans of JSON compress ~10x.
        if ($this->compress && strlen($body) > self::COMPRESSION_THRESHOLD) {
            $compressed = gzencode($body, 6);

            if ($compressed !== false) {
                $body = $compressed;
                $headers[] = 'Content-Encoding: gzip';
            }
        }

        foreach ($this->headers as $name => $value) {
            $headers[] = "{$name}: {$value}";
        }

        $handle = curl_init($url);

        if ($handle === false) {
            return ExportResult::failed('curl_init failed');
        }

        $rawHeaders = '';
        $responseBody = '';
        $truncated = false;

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$rawHeaders): int {
                // Headers count against a bound of their own. Two 40KiB
                // header lines from something that is not a collector
                // would otherwise sail past the response cap below.
                if (strlen($rawHeaders) < self::MAX_RESPONSE_BYTES) {
                    $rawHeaders .= substr($line, 0, self::MAX_RESPONSE_BYTES - strlen($rawHeaders));
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody, &$truncated): int {
                $length = strlen($chunk);

                // Keep the first 64KiB and discard the rest, rather than
                // returning short — a short write aborts the transfer,
                // and curl reports that as a network error, which would
                // trip the circuit breaker on a response that arrived
                // perfectly well.
                if (strlen($responseBody) < self::MAX_RESPONSE_BYTES) {
                    $responseBody .= substr($chunk, 0, self::MAX_RESPONSE_BYTES - strlen($responseBody));
                }

                if (strlen($responseBody) >= self::MAX_RESPONSE_BYTES) {
                    $truncated = true;
                }

                return $length;
            },
            CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->connectTimeout * 1000),
            // Explicit, even though these are curl's defaults — telemetry
            // credentials ride on these requests.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);

        if ($response === false) {
            return ExportResult::unreachable("network error: {$error}");
        }

        if ($status >= 200 && $status < 300) {
            return $this->classifySuccess($responseBody, $truncated);
        }

        if (in_array($status, [429, 502, 503, 504], true)) {
            return ExportResult::retryable(
                $this->describe($status, $responseBody),
                $this->retryAfter($rawHeaders),
            );
        }

        return ExportResult::failed($this->describe($status, $responseBody));
    }

    /**
     * The status plus whatever the backend said about it — the operator
     * reads this in `telemetry:flush` output, and a real collector's JSON
     * error body is usually the whole diagnosis.
     *
     * Collapsed to one line (an HTML error page from a proxy in front of
     * the collector would otherwise spray the console) and cut on a
     * character boundary, not a byte one.
     */
    private function describe(int $status, string $body): string
    {
        $body = trim((string) preg_replace('/\s+/', ' ', $body));

        if ($body === '') {
            return "HTTP {$status}";
        }

        if (mb_strlen($body) > self::MAX_REPORTED_BODY) {
            $body = mb_substr($body, 0, self::MAX_REPORTED_BODY).'… (truncated)';
        }

        return "HTTP {$status}: {$body}";
    }

    private function classifySuccess(string $body, bool $truncated = false): ExportResult
    {
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($body, true);

        // A 2xx whose body we cut in half does not decode, and "does
        // not decode" must not read as "accepted everything". OTLP
        // reports rejections inside a 200, so this is exactly where a
        // silent partial loss would hide — the one bug the transport
        // tests exist for. Reported as accepted, because retrying a
        // batch the backend took would duplicate it, but the count is
        // unknown and the reason says so.
        if ($decoded === null && $truncated && trim($body) !== '') {
            return ExportResult::partial(0, 'the response was too large to read — any partial-success count in it was lost');
        }

        if (is_array($decoded)) {
            foreach ($decoded as $key => $value) {
                if (str_starts_with((string) $key, 'partialSuccess') && is_array($value) && $value !== []) {
                    $rejected = 0;

                    foreach ($value as $field => $count) {
                        if (str_starts_with((string) $field, 'rejected')) {
                            $rejected = Cast::int($count);
                        }
                    }

                    if ($rejected > 0) {
                        $message = $value['errorMessage'] ?? null;

                        return ExportResult::partial($rejected, is_string($message) ? $message : null);
                    }
                }
            }
        }

        return ExportResult::ok();
    }

    /**
     * Retry-After, in either form RFC 9110 allows.
     *
     * Delay-seconds is what a collector usually sends. An HTTP-date is
     * what a proxy or a load balancer in front of one sends, and
     * ignoring it meant falling back to the default cooldown — which
     * is fine when the date is soon and wrong when the operator has
     * told us to come back in an hour.
     */
    private function retryAfter(string $rawHeaders): ?int
    {
        if (preg_match('/^Retry-After:\s*(\d+)\s*$/mi', $rawHeaders, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/^Retry-After:\s*(.+?)\s*$/mi', $rawHeaders, $matches) !== 1) {
            return null;
        }

        $at = strtotime($matches[1]);

        if ($at === false) {
            return null;
        }

        // A date in the past means "now"; a date absurdly far out is
        // clamped, because a cooldown longer than a day is indistinguishable
        // from the exporter being switched off.
        return max(0, min($at - time(), 86_400));
    }
}
