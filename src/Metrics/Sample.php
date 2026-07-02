<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Metrics;

/**
 * A single counter or gauge series: one labelset, one value.
 */
final readonly class Sample
{
    /**
     * @param  array<string, string>  $labels
     */
    public function __construct(
        public array $labels,
        public float $value,
    ) {}
}
