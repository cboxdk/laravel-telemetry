<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Metrics\Stores;

use Cbox\Telemetry\Contracts\MetricStore;
use Cbox\Telemetry\Metrics\MetricDefinition;

/**
 * The disabled-mode store: every write is a no-op.
 */
final class NullMetricStore implements MetricStore
{
    public function incrementCounter(MetricDefinition $definition, array $labels, float $by): void {}

    public function setGauge(MetricDefinition $definition, array $labels, float $value): void {}

    public function addGauge(MetricDefinition $definition, array $labels, float $delta): void {}

    public function recordHistogram(MetricDefinition $definition, array $labels, float $value): void {}

    public function collect(): array
    {
        return [];
    }

    public function wipe(): void {}
}
