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
    ) {}

    public function successful(): bool
    {
        return $this->failures === [];
    }

    public function isEmpty(): bool
    {
        return $this->shipped === 0 && $this->requeued === 0 && $this->dropped === 0;
    }
}
