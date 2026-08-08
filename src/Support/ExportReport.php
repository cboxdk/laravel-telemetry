<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

/**
 * What every exporter did with one flush.
 *
 * The pipeline used to hand batches to the exporters and discard their
 * results, so a backend that rejected every request looked exactly like
 * one that took them. Flushes now return this, and `telemetry:flush`
 * turns it into output, a log line and an exit code.
 */
final readonly class ExportReport
{
    /**
     * @param  list<ExportOutcome>  $outcomes  one per exporter the batch was offered to
     * @param  int  $items  top-level items in the batch — metric families, or spans + events
     */
    public function __construct(
        public array $outcomes = [],
        public int $items = 0,
    ) {}

    public function with(ExportOutcome $outcome): self
    {
        return new self([...$this->outcomes, $outcome], $this->items);
    }

    /**
     * Fold in another flush's report — for a command that flushes more
     * than one signal and answers for all of them at once.
     */
    public function merge(self $other): self
    {
        return new self([...$this->outcomes, ...$other->outcomes], $this->items + $other->items);
    }

    /**
     * Exporters the batch was actually sent to (skipped ones excluded).
     */
    public function attempted(): int
    {
        return count($this->attempts());
    }

    /**
     * Exporters that took the batch.
     */
    public function accepted(): int
    {
        return count(array_filter($this->attempts(), fn (ExportOutcome $outcome): bool => $outcome->accepted()));
    }

    /**
     * @return list<ExportOutcome>
     */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->attempts(),
            fn (ExportOutcome $outcome): bool => ! $outcome->accepted(),
        ));
    }

    /**
     * Exporters that took the batch but refused some of its data points
     * (OTLP partial success) — delivered-ish, and still a loss.
     *
     * @return list<ExportOutcome>
     */
    public function rejections(): array
    {
        return array_values(array_filter(
            $this->attempts(),
            fn (ExportOutcome $outcome): bool => $outcome->accepted() && $outcome->rejected > 0,
        ));
    }

    /**
     * Everything worth telling an operator about: outright failures
     * first, then the partial acceptances.
     *
     * @return list<ExportOutcome>
     */
    public function problems(): array
    {
        return [...$this->failures(), ...$this->rejections()];
    }

    /**
     * Data points the backends refused (OTLP partial success).
     */
    public function rejected(): int
    {
        return array_sum(array_map(fn (ExportOutcome $outcome): int => $outcome->rejected, $this->attempts()));
    }

    /**
     * True when nothing was lost: every exporter that was tried took the
     * batch, whole. An empty report (nothing to send, or no exporter for
     * these signals) is a success — there is nothing that failed.
     */
    public function successful(): bool
    {
        return $this->problems() === [];
    }

    /**
     * "1 of 3 exporters accepted the batch" — the honest headline when
     * some landed and some did not.
     */
    public function summary(): string
    {
        return sprintf(
            '%d of %d exporter%s accepted the batch',
            $this->accepted(),
            $this->attempted(),
            $this->attempted() === 1 ? '' : 's',
        );
    }

    /**
     * @return list<ExportOutcome>
     */
    private function attempts(): array
    {
        return array_values(array_filter(
            $this->outcomes,
            fn (ExportOutcome $outcome): bool => $outcome->status !== ExportStatus::Skipped,
        ));
    }
}
