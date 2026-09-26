<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Metrics\Stores;

use Cbox\Telemetry\Contracts\MetricStore;
use Cbox\Telemetry\Contracts\ReportsOverflow;
use Cbox\Telemetry\Metrics\Exemplar;
use Cbox\Telemetry\Metrics\Labels;
use Cbox\Telemetry\Metrics\MetricDefinition;
use Throwable;

/**
 * Write-buffering decorator around any metric store.
 *
 * Instrument writes aggregate in memory and flush once at terminate
 * (request end, after each queue job, on scrape/export) — a request that
 * increments the same counter 100 times costs ONE store command, and an
 * N+1 page's histogram observations flush as pre-aggregated buckets.
 *
 * Trade-off (same as Laravel Pulse): a hard crash loses the unflushed
 * buffer. The buffer force-flushes at $maxPending operations so
 * long-running workers without flush points stay bounded.
 */
final class BufferedMetricStore implements MetricStore, ReportsOverflow
{
    /** @var array<string, array{definition: MetricDefinition, series: array<string, float>}> */
    private array $counters = [];

    /** @var array<string, array{definition: MetricDefinition, series: array<string, array{set: float|null, add: float}>}> */
    private array $gauges = [];

    /** @var array<string, array{definition: MetricDefinition, series: array<string, array{bucketCounts: list<int>, sum: float, count: int, exemplar: Exemplar|null}>}> */
    private array $histograms = [];

    private int $pending = 0;

    /** Unix time until which the inner store is presumed still down. */
    private int $coolingUntil = 0;

    /** Observations dropped because the buffer was full, for the record. */
    private int $dropped = 0;

    public function __construct(
        private readonly MetricStore $inner,
        private readonly int $maxPending = 1000,
        /**
         * The most distinct series held while the inner store is
         * unreachable. Past it, new SERIES are dropped — existing ones
         * keep aggregating, so a bounded-label application loses
         * nothing and an unbounded one loses the tail rather than the
         * worker.
         */
        private readonly int $maxSeries = 10_000,
        /** Seconds to wait after a failed flush before trying again. */
        private readonly int $cooldownSeconds = 10,
    ) {}

    /**
     * Is there room for a series that is not already buffered?
     *
     * The buffer retains whatever a failed flush could not deliver, so
     * during an outage it grows by every distinct series the
     * application touches. An independent review reproduced twenty
     * thousand of them against an always-failing store; in an Octane
     * or queue worker that is the process, not a metric.
     *
     * @phpstan-impure it counts what it turns away, so two calls with
     *                 the same arguments are not the same call
     */
    private function hasRoomFor(string $family, string $series, string $kind): bool
    {
        $existing = match ($kind) {
            'counter' => $this->counters[$family]['series'][$series] ?? null,
            'gauge' => $this->gauges[$family]['series'][$series] ?? null,
            default => $this->histograms[$family]['series'][$series] ?? null,
        };

        if ($existing !== null) {
            return true;
        }

        if ($this->seriesCount() < $this->maxSeries) {
            return true;
        }

        $this->dropped++;

        return false;
    }

    private function seriesCount(): int
    {
        $count = 0;

        foreach ([$this->counters, $this->gauges, $this->histograms] as $kind) {
            foreach ($kind as $family) {
                $count += count($family['series']);
            }
        }

        return $count;
    }

    /**
     * How many observations this buffer has thrown away, and counting.
     *
     * Exposed rather than merely counted: a number that only exists
     * inside an object nobody asks is the same as no number, and
     * "metrics are missing" has to be answerable.
     */
    public function droppedObservations(): int
    {
        return $this->dropped;
    }

    public function incrementCounter(MetricDefinition $definition, array $labels, float $by): void
    {
        $series = Labels::encode($labels);

        if (! $this->hasRoomFor($definition->name, $series, 'counter')) {
            return;
        }

        $this->counters[$definition->name] ??= ['definition' => $definition, 'series' => []];
        $this->counters[$definition->name]['series'][$series] ??= 0.0;
        $this->counters[$definition->name]['series'][$series] += $by;

        $this->bumpPending();
    }

    public function setGauge(MetricDefinition $definition, array $labels, float $value): void
    {
        $series = Labels::encode($labels);

        if (! $this->hasRoomFor($definition->name, $series, 'gauge')) {
            return;
        }

        $this->gauges[$definition->name] ??= ['definition' => $definition, 'series' => []];
        // A set supersedes anything buffered for the series.
        $this->gauges[$definition->name]['series'][$series] = ['set' => $value, 'add' => 0.0];

        $this->bumpPending();
    }

    public function addGauge(MetricDefinition $definition, array $labels, float $delta): void
    {
        $series = Labels::encode($labels);

        if (! $this->hasRoomFor($definition->name, $series, 'gauge')) {
            return;
        }

        $this->gauges[$definition->name] ??= ['definition' => $definition, 'series' => []];
        $entry = $this->gauges[$definition->name]['series'][$series] ?? ['set' => null, 'add' => 0.0];

        // Deltas after a buffered set fold into the set value; otherwise
        // they accumulate and flush as one atomic add.
        if ($entry['set'] !== null) {
            $entry['set'] += $delta;
        } else {
            $entry['add'] += $delta;
        }

        $this->gauges[$definition->name]['series'][$series] = $entry;

        $this->bumpPending();
    }

    public function recordHistogram(MetricDefinition $definition, array $labels, float $value, ?Exemplar $exemplar = null): void
    {
        $bounds = $definition->buckets ?? [];
        $bucketIndex = $this->bucketIndex($bounds, $value);

        $bucketCounts = [];

        for ($i = 0, $slots = count($bounds) + 1; $i < $slots; $i++) {
            $bucketCounts[] = $i === $bucketIndex ? 1 : 0;
        }

        $this->mergeHistogram($definition, $labels, $bucketCounts, $value, 1, $exemplar);
    }

    public function mergeHistogram(MetricDefinition $definition, array $labels, array $bucketCounts, float $sum, int $count, ?Exemplar $exemplar = null): void
    {
        $series = Labels::encode($labels);

        if (! $this->hasRoomFor($definition->name, $series, 'histogram')) {
            return;
        }

        if (! $this->hasRoomFor($definition->name, $series, 'histogram')) {
            return;
        }

        $this->histograms[$definition->name] ??= ['definition' => $definition, 'series' => []];
        $this->histograms[$definition->name]['series'][$series] ??= [
            'bucketCounts' => array_fill(0, count($definition->buckets ?? []) + 1, 0),
            'sum' => 0.0,
            'count' => 0,
            'exemplar' => null,
        ];

        $entry = &$this->histograms[$definition->name]['series'][$series];

        foreach ($bucketCounts as $index => $bucketCount) {
            if (isset($entry['bucketCounts'][$index])) {
                $entry['bucketCounts'][$index] += $bucketCount;
            }
        }

        $entry['sum'] += $sum;
        $entry['count'] += $count;

        if ($exemplar !== null) {
            $entry['exemplar'] = $exemplar;
        }

        $this->bumpPending();
    }

    /**
     * Push the aggregated buffer to the inner store.
     *
     * Each series is dropped from the buffer the moment its write lands.
     * Clearing only at the end meant that a store throwing partway — a Redis
     * blip on the third counter — left every write that had ALREADY succeeded
     * sitting in the buffer, and the next flush applied it a second time.
     * Counters and histogram counts inflated by exactly the successful prefix,
     * once per failure, which in a long-running worker compounds every time
     * the same later write fails.
     *
     * Dropping as we go makes a partial flush exactly-once for what landed and
     * leaves only the remainder to retry.
     */
    public function flushBuffer(): void
    {
        foreach ($this->counters as $name => $family) {
            foreach ($family['series'] as $series => $delta) {
                $this->inner->incrementCounter($family['definition'], Labels::decode($series), $delta);
                unset($this->counters[$name]['series'][$series]);
            }

            unset($this->counters[$name]);
        }

        foreach ($this->gauges as $name => $family) {
            foreach ($family['series'] as $series => $entry) {
                if ($entry['set'] !== null) {
                    $this->inner->setGauge($family['definition'], Labels::decode($series), $entry['set']);
                } elseif ($entry['add'] !== 0.0) {
                    $this->inner->addGauge($family['definition'], Labels::decode($series), $entry['add']);
                }

                unset($this->gauges[$name]['series'][$series]);
            }

            unset($this->gauges[$name]);
        }

        foreach ($this->histograms as $name => $family) {
            foreach ($family['series'] as $series => $entry) {
                $this->inner->mergeHistogram(
                    $family['definition'],
                    Labels::decode($series),
                    $entry['bucketCounts'],
                    $entry['sum'],
                    $entry['count'],
                    $entry['exemplar'],
                );

                unset($this->histograms[$name]['series'][$series]);
            }

            unset($this->histograms[$name]);
        }

        $this->pending = 0;
    }

    /**
     * Scrapes and exports must see everything written so far.
     */
    /**
     * Straight through to the store behind the buffer — the budget is
     * enforced where the series actually live.
     *
     * @return array<string, int>
     */
    public function overflowingFamilies(): array
    {
        return $this->inner instanceof ReportsOverflow ? $this->inner->overflowingFamilies() : [];
    }

    public function collect(): array
    {
        $this->flushBuffer();

        return $this->inner->collect();
    }

    public function wipe(): void
    {
        $this->counters = [];
        $this->gauges = [];
        $this->histograms = [];
        $this->pending = 0;

        $this->inner->wipe();
    }

    public function forgetSeries(MetricDefinition $definition, array $labels): void
    {
        $series = Labels::encode($labels);

        // Drop anything still buffered for the series so a later flush
        // can't resurrect what the inner store just forgot.
        unset(
            $this->counters[$definition->name]['series'][$series],
            $this->gauges[$definition->name]['series'][$series],
            $this->histograms[$definition->name]['series'][$series],
        );

        $this->inner->forgetSeries($definition, $labels);
    }

    public function inner(): MetricStore
    {
        return $this->inner;
    }

    private function bumpPending(): void
    {
        if (++$this->pending < $this->maxPending) {
            return;
        }

        // A cooldown after a failure, because this is reached from an
        // ORDINARY observation — a counter increment on the request
        // path. Without it, a store that is down is retried by every
        // subsequent observation, each paying its connect timeout, and
        // the application spends its time discovering the same outage
        // over and over.
        if (time() < $this->coolingUntil) {
            return;
        }

        try {
            $this->flushBuffer();
            $this->coolingUntil = 0;
        } catch (Throwable $e) {
            $this->coolingUntil = time() + $this->cooldownSeconds;

            throw $e;
        }
    }

    /**
     * @param  list<float>  $bounds
     */
    private function bucketIndex(array $bounds, float $value): int
    {
        foreach ($bounds as $index => $bound) {
            if ($value <= $bound) {
                return $index;
            }
        }

        return count($bounds);
    }
}
