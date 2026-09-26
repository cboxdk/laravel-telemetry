<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Contracts;

/**
 * A store that can say which metric families hit their series budget.
 *
 * Separate from MetricStore because it is a diagnostic, not part of
 * writing or reading metrics — and because only a shared store can
 * answer it honestly. An in-process store has no budget to exceed.
 */
interface ReportsOverflow
{
    /**
     * Families over their budget, and how many series each refused.
     *
     * @return array<string, int>
     */
    public function overflowingFamilies(): array;
}
