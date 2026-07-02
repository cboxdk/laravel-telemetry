<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Metrics\Stores;

use Cbox\Telemetry\Contracts\MetricStore;
use Cbox\Telemetry\Metrics\HistogramSample;
use Cbox\Telemetry\Metrics\Labels;
use Cbox\Telemetry\Metrics\MetricDefinition;
use Cbox\Telemetry\Metrics\MetricFamily;
use Cbox\Telemetry\Metrics\Sample;

/**
 * In-process store for tests and single-request use.
 *
 * Backs Telemetry::fake() — state lives in this object only.
 */
final class ArrayMetricStore implements MetricStore
{
    /** @var array<string, array{definition: MetricDefinition, series: array<string, float>}> */
    private array $counters = [];

    /** @var array<string, array{definition: MetricDefinition, series: array<string, float>}> */
    private array $gauges = [];

    /** @var array<string, array{definition: MetricDefinition, series: array<string, array{bucketCounts: list<int>, sum: float, count: int}>}> */
    private array $histograms = [];

    /** @var array<string, int> first-write timestamps (unix nanos) */
    private array $since = [];

    public function incrementCounter(MetricDefinition $definition, array $labels, float $by): void
    {
        $key = Labels::encode($labels);
        $this->since[$definition->name] ??= (int) (microtime(true) * 1e9);

        $this->counters[$definition->name] ??= ['definition' => $definition, 'series' => []];
        $this->counters[$definition->name]['series'][$key] ??= 0.0;
        $this->counters[$definition->name]['series'][$key] += $by;
    }

    public function setGauge(MetricDefinition $definition, array $labels, float $value): void
    {
        $key = Labels::encode($labels);
        $this->since[$definition->name] ??= (int) (microtime(true) * 1e9);

        $this->gauges[$definition->name] ??= ['definition' => $definition, 'series' => []];
        $this->gauges[$definition->name]['series'][$key] = $value;
    }

    public function addGauge(MetricDefinition $definition, array $labels, float $delta): void
    {
        $key = Labels::encode($labels);
        $this->since[$definition->name] ??= (int) (microtime(true) * 1e9);

        $this->gauges[$definition->name] ??= ['definition' => $definition, 'series' => []];
        $this->gauges[$definition->name]['series'][$key] ??= 0.0;
        $this->gauges[$definition->name]['series'][$key] += $delta;
    }

    public function recordHistogram(MetricDefinition $definition, array $labels, float $value): void
    {
        $key = Labels::encode($labels);
        $bounds = $definition->buckets ?? [];
        $this->since[$definition->name] ??= (int) (microtime(true) * 1e9);

        $this->histograms[$definition->name] ??= ['definition' => $definition, 'series' => []];
        $this->histograms[$definition->name]['series'][$key] ??= [
            'bucketCounts' => array_fill(0, count($bounds) + 1, 0),
            'sum' => 0.0,
            'count' => 0,
        ];

        $series = &$this->histograms[$definition->name]['series'][$key];
        $series['bucketCounts'][$this->bucketIndex($bounds, $value)]++;
        $series['sum'] += $value;
        $series['count']++;
    }

    public function mergeHistogram(MetricDefinition $definition, array $labels, array $bucketCounts, float $sum, int $count): void
    {
        $key = Labels::encode($labels);
        $bounds = $definition->buckets ?? [];
        $this->since[$definition->name] ??= (int) (microtime(true) * 1e9);

        $this->histograms[$definition->name] ??= ['definition' => $definition, 'series' => []];
        $this->histograms[$definition->name]['series'][$key] ??= [
            'bucketCounts' => array_fill(0, count($bounds) + 1, 0),
            'sum' => 0.0,
            'count' => 0,
        ];

        $series = &$this->histograms[$definition->name]['series'][$key];

        foreach ($bucketCounts as $index => $bucketCount) {
            if (isset($series['bucketCounts'][$index])) {
                $series['bucketCounts'][$index] += $bucketCount;
            }
        }

        $series['sum'] += $sum;
        $series['count'] += $count;
    }

    public function collect(): array
    {
        $families = [];

        foreach ([...$this->counters, ...$this->gauges] as $entry) {
            $samples = [];

            foreach ($entry['series'] as $encoded => $value) {
                $samples[] = new Sample(Labels::decode($encoded), $value);
            }

            $families[] = new MetricFamily($entry['definition'], $samples, $this->since[$entry['definition']->name] ?? null);
        }

        foreach ($this->histograms as $entry) {
            $samples = [];
            $bounds = $entry['definition']->buckets ?? [];

            foreach ($entry['series'] as $encoded => $series) {
                $samples[] = new HistogramSample(
                    labels: Labels::decode($encoded),
                    bounds: $bounds,
                    bucketCounts: $series['bucketCounts'],
                    sum: $series['sum'],
                    count: $series['count'],
                );
            }

            $families[] = new MetricFamily($entry['definition'], $samples, $this->since[$entry['definition']->name] ?? null);
        }

        return $families;
    }

    public function wipe(): void
    {
        $this->counters = [];
        $this->gauges = [];
        $this->histograms = [];
        $this->since = [];
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
