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
     */
    public function __construct(
        public MetricDefinition $definition,
        public array $samples,
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
