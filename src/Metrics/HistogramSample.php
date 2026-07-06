<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Metrics;

/**
 * A single histogram series.
 *
 * Bucket counts are NON-cumulative and one longer than the bounds list
 * (the final slot is the overflow/+Inf bucket) — matching the OTLP data
 * model. The Prometheus renderer accumulates at render time.
 */
final readonly class HistogramSample
{
    /**
     * @param  array<string, string>  $labels
     * @param  list<float>  $bounds
     * @param  list<int>  $bucketCounts
     */
    public function __construct(
        public array $labels,
        public array $bounds,
        public array $bucketCounts,
        public float $sum,
        public int $count,
        public ?Exemplar $exemplar = null,
    ) {}
}
