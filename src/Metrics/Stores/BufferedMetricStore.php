<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Metrics\Stores;

use Cbox\Telemetry\Contracts\MetricStore;
use Cbox\Telemetry\Metrics\Exemplar;
use Cbox\Telemetry\Metrics\Labels;
use Cbox\Telemetry\Metrics\MetricDefinition;

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
final class BufferedMetricStore implements MetricStore
{
    /** @var array<string, array{definition: MetricDefinition, series: array<string, float>}> */
    private array $counters = [];

    /** @var array<string, array{definition: MetricDefinition, series: array<string, array{set: float|null, add: float}>}> */
    private array $gauges = [];

    /** @var array<string, array{definition: MetricDefinition, series: array<string, array{bucketCounts: list<int>, sum: float, count: int, exemplar: Exemplar|null}>}> */
    private array $histograms = [];

    private int $pending = 0;

    public function __construct(
        private readonly MetricStore $inner,
        private readonly int $maxPending = 1000,
    ) {}

    public function incrementCounter(MetricDefinition $definition, array $labels, float $by): void
    {
        $series = Labels::encode($labels);

        $this->counters[$definition->name] ??= ['definition' => $definition, 'series' => []];
        $this->counters[$definition->name]['series'][$series] ??= 0.0;
        $this->counters[$definition->name]['series'][$series] += $by;

        $this->bumpPending();
    }

    public function setGauge(MetricDefinition $definition, array $labels, float $value): void
    {
        $series = Labels::encode($labels);

        $this->gauges[$definition->name] ??= ['definition' => $definition, 'series' => []];
        // A set supersedes anything buffered for the series.
        $this->gauges[$definition->name]['series'][$series] = ['set' => $value, 'add' => 0.0];

        $this->bumpPending();
    }

    public function addGauge(MetricDefinition $definition, array $labels, float $delta): void
    {
        $series = Labels::encode($labels);

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
     */
    public function flushBuffer(): void
    {
        foreach ($this->counters as $family) {
            foreach ($family['series'] as $series => $delta) {
                $this->inner->incrementCounter($family['definition'], Labels::decode($series), $delta);
            }
        }

        foreach ($this->gauges as $family) {
            foreach ($family['series'] as $series => $entry) {
                if ($entry['set'] !== null) {
                    $this->inner->setGauge($family['definition'], Labels::decode($series), $entry['set']);
                } elseif ($entry['add'] !== 0.0) {
                    $this->inner->addGauge($family['definition'], Labels::decode($series), $entry['add']);
                }
            }
        }

        foreach ($this->histograms as $family) {
            foreach ($family['series'] as $series => $entry) {
                $this->inner->mergeHistogram(
                    $family['definition'],
                    Labels::decode($series),
                    $entry['bucketCounts'],
                    $entry['sum'],
                    $entry['count'],
                    $entry['exemplar'],
                );
            }
        }

        $this->counters = [];
        $this->gauges = [];
        $this->histograms = [];
        $this->pending = 0;
    }

    /**
     * Scrapes and exports must see everything written so far.
     */
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

    public function inner(): MetricStore
    {
        return $this->inner;
    }

    private function bumpPending(): void
    {
        if (++$this->pending >= $this->maxPending) {
            $this->flushBuffer();
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
