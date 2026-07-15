<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Contracts;

use Cbox\Telemetry\Metrics\Exemplar;
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
     * @param  Exemplar|null  $exemplar  A representative sampled-trace
     *                                   observation — overwrites the
     *                                   family's single stored exemplar,
     *                                   never aggregated. Optional: not
     *                                   every observation happens inside
     *                                   a sampled trace.
     */
    public function recordHistogram(MetricDefinition $definition, array $labels, float $value, ?Exemplar $exemplar = null): void;

    /**
     * Merge pre-aggregated histogram data (bucket counts, sum, count) in
     * one operation — the write path for buffered stores flushing many
     * observations at once.
     *
     * @param  array<string, string>  $labels
     * @param  list<int>  $bucketCounts  One slot per bound plus overflow.
     */
    public function mergeHistogram(MetricDefinition $definition, array $labels, array $bucketCounts, float $sum, int $count, ?Exemplar $exemplar = null): void;

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

    /**
     * Remove a single stored series (one labelset) from a family.
     *
     * Used to retire per-process series — e.g. the pid-labeled worker
     * memory gauges — when the owning process exits, so dead series don't
     * accumulate in the shared store forever. Only call this for series
     * no other live process writes to.
     *
     * @param  array<string, string>  $labels
     */
    public function forgetSeries(MetricDefinition $definition, array $labels): void;
}
