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
     * Permanent failure — retrying will not help (4xx, serialization).
     */
    public static function failed(?string $reason = null): self
    {
        return new self(success: false, retryable: false, reason: $reason);
    }
}
