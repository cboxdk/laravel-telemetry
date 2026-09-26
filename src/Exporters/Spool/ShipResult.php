<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Exporters\Spool;

use Cbox\Telemetry\Support\ExportOutcome;

/**
 * What one spool drain achieved.
 *
 * The counts alone could not tell an operator whether "dropped: 40" meant
 * a malformed payload or a backend rejecting everything, so the failures
 * travel with them — reason and all — for `telemetry:flush` to print.
 */
final readonly class ShipResult
{
    /**
     * @param  int  $shipped  entries the backend took
     * @param  int  $requeued  entries put back for the next tick (endpoint unreachable)
     * @param  int  $dropped  entries discarded — permanently rejected, and gone
     * @param  list<ExportOutcome>  $failures  one per rejected signal post
     * @param  bool  $drained  false when the drain stopped on its budget rather
     *                         than on an empty spool — there is more waiting,
     *                         and the caller should come back immediately
     */
    public function __construct(
        public int $shipped = 0,
        public int $requeued = 0,
        public int $dropped = 0,
        public array $failures = [],
        public bool $drained = true,
        // Set when the drain did nothing because the BACKEND asked it
        // to wait. Distinct from an unfinished drain, which means come
        // straight back: a caller that could not tell the two apart
        // spun at full speed for the length of the cooldown.
        public bool $waiting = false,
    ) {}

    public function successful(): bool
    {
        return $this->failures === [];
    }

    /**
     * Fold another drain's counts into this one — a one-shot run
     * reports what all of its passes achieved, not what the last one
     * did.
     */
    public function plus(self $other): self
    {
        return new self(
            $this->shipped + $other->shipped,
            $this->requeued + $other->requeued,
            $this->dropped + $other->dropped,
            [...$this->failures, ...$other->failures],
            $other->drained,
            $other->waiting,
        );
    }

    public function isEmpty(): bool
    {
        return $this->shipped === 0 && $this->requeued === 0 && $this->dropped === 0;
    }
}
