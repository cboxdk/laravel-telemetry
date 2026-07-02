<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Metrics;

/**
 * A named metric with all of its series, ready for export.
 */
final readonly class MetricFamily
{
    /**
     * @param  list<Sample>|list<HistogramSample>  $samples
     * @param  int|null  $startUnixNano  First-ever write time — OTLP
     *                                   cumulative start timestamp.
     */
    public function __construct(
        public MetricDefinition $definition,
        public array $samples,
        public ?int $startUnixNano = null,
    ) {}

    public function name(): string
    {
        return $this->definition->name;
    }

    public function type(): MetricType
    {
        return $this->definition->type;
    }
}
