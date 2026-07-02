<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Contracts;

use Cbox\Telemetry\Metrics\MetricDefinition;
use Cbox\Telemetry\Metrics\MetricFamily;

/**
 * Shared storage for push instruments.
 *
 * PHP is shared-nothing, so counter/gauge/histogram state must live outside
 * the process to aggregate across web workers, queue workers and nodes.
 * Implementations must be atomic per write and must never scan the full
 * keyspace at collect time.
 */
interface MetricStore
{
    /**
     * @param  array<string, string>  $labels
     */
    public function incrementCounter(MetricDefinition $definition, array $labels, float $by): void;

    /**
     * @param  array<string, string>  $labels
     */
    public function setGauge(MetricDefinition $definition, array $labels, float $value): void;

    /**
     * Atomically adjust a gauge by a (possibly negative) delta.
     *
     * @param  array<string, string>  $labels
     */
    public function addGauge(MetricDefinition $definition, array $labels, float $delta): void;

    /**
     * @param  array<string, string>  $labels
     */
    public function recordHistogram(MetricDefinition $definition, array $labels, float $value): void;

    /**
     * Collect every stored metric family (push instruments only —
     * observable gauges are evaluated by the registry, not the store).
     *
     * @return list<MetricFamily>
     */
    public function collect(): array;

    /**
     * Remove all stored metric state.
     */
    public function wipe(): void;
}
