<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Exporters\Otlp;

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
    private const COMPRESSION_THRESHOLD = 1024;

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
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
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

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->connectTimeout * 1000),
            // Explicit, even though these are curl's defaults — telemetry
            // credentials ride on these requests.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $error = curl_error($handle);

        if ($response === false || $response === true) {
            return ExportResult::retryable("network error: {$error}");
        }

        $rawHeaders = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);

        if ($status >= 200 && $status < 300) {
            return $this->classifySuccess($responseBody);
        }

        if (in_array($status, [429, 502, 503, 504], true)) {
            return ExportResult::retryable(
                "HTTP {$status}",
                $this->retryAfter($rawHeaders),
            );
        }

        return ExportResult::failed("HTTP {$status}: ".substr($responseBody, 0, 500));
    }

    private function classifySuccess(string $body): ExportResult
    {
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            foreach ($decoded as $key => $value) {
                if (str_starts_with((string) $key, 'partialSuccess') && is_array($value) && $value !== []) {
                    $rejected = 0;

                    foreach ($value as $field => $count) {
                        if (str_starts_with((string) $field, 'rejected')) {
                            $rejected = (int) $count;
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

    private function retryAfter(string $rawHeaders): ?int
    {
        if (preg_match('/^Retry-After:\s*(\d+)/mi', $rawHeaders, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }
}
