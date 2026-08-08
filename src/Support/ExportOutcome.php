<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

/**
 * One exporter's answer for one batch, named so a human can act on it.
 *
 * An ExportResult says what happened; an ExportOutcome says who it
 * happened to. The flush command prints these, so the reason must carry
 * enough detail — status code and the backend's own error body — to
 * diagnose a rejection without a packet capture.
 */
final readonly class ExportOutcome
{
    private function __construct(
        public string $exporter,
        public ExportStatus $status,
        public ?string $reason = null,
        public int $rejected = 0,
    ) {}

    public static function of(string $exporter, ExportResult $result): self
    {
        $status = match (true) {
            $result->rejected > 0 => ExportStatus::Partial,
            $result->success => ExportStatus::Ok,
            $result->retryable => ExportStatus::Retryable,
            default => ExportStatus::Failed,
        };

        return new self($exporter, $status, $result->reason, $result->rejected);
    }

    /**
     * The exporter threw instead of returning a result — a contract
     * violation (Exporter::export must never throw), reported as such
     * rather than counted as a success.
     */
    public static function threw(string $exporter): self
    {
        return new self(
            $exporter,
            ExportStatus::Error,
            'the exporter threw — see the configured exception handler for the trace',
        );
    }

    /**
     * The exporter supports none of the batch's signals, so nothing was
     * sent. Not a failure, and not a delivery either.
     */
    public static function skipped(string $exporter): self
    {
        return new self($exporter, ExportStatus::Skipped);
    }

    public function accepted(): bool
    {
        return $this->status->accepted();
    }

    /**
     * A single line fit for a console detail row or a log message.
     */
    public function describe(): string
    {
        $detail = match ($this->status) {
            ExportStatus::Ok => 'accepted',
            ExportStatus::Partial => "accepted, but rejected {$this->rejected} data point(s)",
            ExportStatus::Retryable => 'temporarily rejected — will be retried',
            ExportStatus::Failed => 'rejected',
            ExportStatus::Error => 'errored',
            ExportStatus::Skipped => 'skipped — handles none of these signals',
        };

        if ($this->reason === null || $this->reason === '') {
            return $detail;
        }

        return "{$detail}: {$this->reason}";
    }
}
