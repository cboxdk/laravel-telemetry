<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Contracts;

/**
 * A metric store shared by several hosts that can elect one of them to export.
 *
 * A store every host writes to holds ONE cumulative total per series, and
 * every host derives the same service.instance.id for it (ServiceInstance).
 * When each host's `telemetry:flush --daemon` exported it, the backend got
 * the same series from every host — duplicate samples at slightly different
 * times — and the store was read in full by every host each interval. A
 * claim lets one host export per interval and the rest skip.
 */
interface ClaimsMetricExport
{
    /**
     * Claim this interval's export. True for the one caller that gets it;
     * the claim lapses on its own after $seconds.
     */
    public function claimExport(int $seconds): bool;
}
