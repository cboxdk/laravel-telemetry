<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

/**
 * Outcome of an export attempt.
 *
 * Exporters classify failures; the pipeline (or the scheduled flush command)
 * decides whether and when to retry.
 */
final readonly class ExportResult
{
    private function __construct(
        public bool $success,
        public bool $retryable,
        public ?string $reason = null,
        public int $rejected = 0,
        public ?int $retryAfterSeconds = null,
        public bool $unreachable = false,
    ) {}

    public static function ok(): self
    {
        return new self(success: true, retryable: false);
    }

    /**
     * The backend accepted the batch but rejected some items
     * (OTLP partial success).
     */
    public static function partial(int $rejected, ?string $reason = null): self
    {
        return new self(success: true, retryable: false, reason: $reason, rejected: $rejected);
    }

    /**
     * Transient failure — safe to retry (429/503, timeouts, connection loss).
     */
    public static function retryable(?string $reason = null, ?int $retryAfterSeconds = null): self
    {
        return new self(success: false, retryable: true, reason: $reason, retryAfterSeconds: $retryAfterSeconds);
    }

    /**
     * The endpoint could not be reached at all — a refused connection,
     * a DNS failure, a timeout.
     *
     * Distinct from an ordinary retryable failure because of what it
     * says about the NEXT call: a 503 came back from a server that
     * answered promptly, so there is every reason to try the rest of
     * the batch; a connect timeout means the next signal pays the same
     * timeout for the same answer.
     */
    public static function unreachable(?string $reason = null, ?int $retryAfterSeconds = null): self
    {
        return new self(success: false, retryable: true, reason: $reason, retryAfterSeconds: $retryAfterSeconds, unreachable: true);
    }

    /**
     * Permanent failure — retrying will not help (4xx, serialization).
     */
    public static function failed(?string $reason = null): self
    {
        return new self(success: false, retryable: false, reason: $reason);
    }
}
