<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Exporters\Prometheus;

use Cbox\Telemetry\Metrics\Exemplar;
use Cbox\Telemetry\Metrics\HistogramSample;
use Cbox\Telemetry\Metrics\MetricFamily;
use Cbox\Telemetry\Metrics\MetricType;
use Cbox\Telemetry\Metrics\Sample;

/**
 * Renders metric families to the Prometheus text exposition format.
 *
 * Names are converted from OTel dot notation to Prometheus underscores;
 * the unit becomes a name suffix (`_milliseconds`, `_bytes`, …) per
 * Prometheus/OpenMetrics convention; counters get the conventional `_total`
 * suffix; histogram buckets are accumulated into cumulative `le` buckets at
 * render time.
 */
final class PrometheusRenderer
{
    /** @var array<string, string> resource identity labels stamped on every series */
    private array $resourceLabels = [];

    public const MIME_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

    /**
     * Exemplars (the histogram → trace id bridge) are only valid in
     * OpenMetrics — the classic text format has no grammar for a trailing
     * `# {...}` on a sample line, so a scraper on MIME_TYPE never sees them.
     */
    public const OPENMETRICS_MIME_TYPE = 'application/openmetrics-text; version=1.0.0; charset=utf-8';

    /**
     * @param  list<MetricFamily>  $families
     * @param  array<string, string>  $resourceLabels  service_name/host_name/… stamped on every series so a single Prometheus scraping many apps can tell them apart
     */
    public function render(array $families, array $resourceLabels = [], bool $openMetrics = false): string
    {
        $this->resourceLabels = $resourceLabels;
        $output = [];

        foreach ($this->deduplicate($families) as $family) {
            $name = $family->definition->prometheusName().$this->unitSuffix($family->definition->unit);

            if ($family->type() === MetricType::Counter) {
                $name .= '_total';
            }

            $help = $family->definition->description;

            if ($family->definition->unit !== '') {
                $help = trim("{$help} (unit: {$family->definition->unit})");
            }

            if ($help !== '') {
                $output[] = '# HELP '.$name.' '.$this->escapeHelp($help);
            }

            $output[] = '# TYPE '.$name.' '.$family->type()->value;

            foreach ($family->samples as $sample) {
                if ($sample instanceof HistogramSample) {
                    array_push($output, ...$this->renderHistogram($name, $sample, $openMetrics));
                } elseif ($sample instanceof Sample) {
                    $output[] = $name.$this->renderLabels($sample->labels).' '.$this->formatValue($sample->value);
                }
            }
        }

        if ($openMetrics) {
            $output[] = '# EOF';

            return implode("\n", $output)."\n";
        }

        return $output === [] ? '' : implode("\n", $output)."\n";
    }

    /**
     * A duplicate family name would fail the entire Prometheus scrape
     * ("duplicate metric family"). Merge same-type duplicates (e.g. a
     * stored push gauge and an observable from another process sharing a
     * name); on a type conflict the first family wins.
     *
     * @param  list<MetricFamily>  $families
     * @return list<MetricFamily>
     */
    private function deduplicate(array $families): array
    {
        /** @var array<string, MetricFamily> $byName */
        $byName = [];

        foreach ($families as $family) {
            $existing = $byName[$family->name()] ?? null;

            if ($existing === null) {
                $byName[$family->name()] = $family;

                continue;
            }

            if ($existing->type() === $family->type()) {
                $byName[$family->name()] = new MetricFamily(
                    $existing->definition,
                    [...$existing->samples, ...$family->samples],
                    $existing->startUnixNano ?? $family->startUnixNano,
                );
            }
        }

        return array_values($byName);
    }

    /**
     * The unit as a Prometheus name suffix. OTel/UCUM unit → Prometheus base
     * unit word (`ms` → `_milliseconds`, `By` → `_bytes`); unknown or unitless
     * ('1', '', 'count') get nothing. Placed before `_total`/`_bucket`, so a
     * `ms` counter reads `<name>_milliseconds_total`.
     */
    private function unitSuffix(string $unit): string
    {
        return match ($unit) {
            'ms' => '_milliseconds',
            's' => '_seconds',
            'By', 'bytes' => '_bytes',
            'By/s' => '_bytes_per_second',
            '%' => '_percent',
            default => '',
        };
    }

    /**
     * @return list<string>
     */
    private function renderHistogram(string $name, HistogramSample $sample, bool $openMetrics = false): array
    {
        $lines = [];
        $cumulative = 0;
        $exemplarBucket = $openMetrics ? $this->exemplarBucketIndex($sample) : null;

        foreach ($sample->bounds as $index => $bound) {
            $cumulative += $sample->bucketCounts[$index] ?? 0;

            $line = $name.'_bucket'.$this->renderLabels($sample->labels, ['le' => $this->formatValue($bound)]).' '.$cumulative;

            if ($index === $exemplarBucket && $sample->exemplar !== null) {
                $line .= ' '.$this->renderExemplar($sample->exemplar);
            }

            $lines[] = $line;
        }

        // The +Inf bucket must never be below the cumulated buckets, even
        // when non-atomic stores let count lag momentarily.
        $total = max($sample->count, $cumulative + ($sample->bucketCounts[count($sample->bounds)] ?? 0));

        $infLine = $name.'_bucket'.$this->renderLabels($sample->labels, ['le' => '+Inf']).' '.$total;

        if ($exemplarBucket === count($sample->bounds) && $sample->exemplar !== null) {
            $infLine .= ' '.$this->renderExemplar($sample->exemplar);
        }

        $lines[] = $infLine;
        $lines[] = $name.'_sum'.$this->renderLabels($sample->labels).' '.$this->formatValue($sample->sum);
        $lines[] = $name.'_count'.$this->renderLabels($sample->labels).' '.$total;

        return $lines;
    }

    /**
     * The bucket the exemplar's own observation landed in — the first
     * bound at or above its value, or the +Inf slot (index === count of
     * bounds) when it exceeds every bound.
     */
    private function exemplarBucketIndex(HistogramSample $sample): ?int
    {
        if ($sample->exemplar === null) {
            return null;
        }

        foreach ($sample->bounds as $index => $bound) {
            if ($sample->exemplar->value <= $bound) {
                return $index;
            }
        }

        return count($sample->bounds);
    }

    private function renderExemplar(Exemplar $exemplar): string
    {
        $seconds = $exemplar->timeUnixNano / 1_000_000_000;

        return '# {trace_id="'.$this->escapeLabelValue($exemplar->traceId).'"} '
            .$this->formatValue($exemplar->value).' '.$this->formatValue($seconds);
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array<string, string>  $extra
     */
    private function renderLabels(array $labels, array $extra = []): string
    {
        $all = [...$this->resourceLabels, ...$labels, ...$extra];

        if ($all === []) {
            return '';
        }

        $parts = [];

        foreach ($all as $key => $value) {
            // Array keys may be ints (json_decode of numeric label names).
            $parts[] = $this->sanitizeLabelName((string) $key).'="'.$this->escapeLabelValue($value).'"';
        }

        return '{'.implode(',', $parts).'}';
    }

    private function sanitizeLabelName(string $name): string
    {
        $name = (string) preg_replace('/[^a-zA-Z0-9_]/', '_', $name);

        // Label names must not start with a digit.
        return preg_match('/^[0-9]/', $name) === 1 ? '_'.$name : $name;
    }

    private function escapeLabelValue(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }

    private function escapeHelp(string $help): string
    {
        return str_replace(['\\', "\n"], ['\\\\', '\\n'], $help);
    }

    private function formatValue(float $value): string
    {
        if (is_infinite($value)) {
            return $value > 0 ? '+Inf' : '-Inf';
        }

        if (is_nan($value)) {
            return 'NaN';
        }

        return (string) $value;
    }
}
