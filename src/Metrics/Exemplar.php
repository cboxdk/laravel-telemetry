<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Metrics;

/**
 * The bridge between metrics and traces: "click this slow bucket, see an
 * actual trace that landed in it." Per Prometheus/OpenMetrics, a histogram
 * carries at most ONE exemplar overall in this package (not one per
 * bucket) — the most recent observation made inside a sampled trace,
 * rendered on whichever bucket line that observation's value falls into.
 * A simplification of the full per-bucket-exemplar spec, traded for not
 * needing a schema change to every bucket field in every store.
 */
final readonly class Exemplar
{
    public function __construct(
        public string $traceId,
        public float $value,
        public int $timeUnixNano,
    ) {}
}
