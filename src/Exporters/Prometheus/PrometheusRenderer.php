<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Exporters\Prometheus;

use Cbox\Telemetry\Metrics\HistogramSample;
use Cbox\Telemetry\Metrics\MetricFamily;
use Cbox\Telemetry\Metrics\MetricType;
use Cbox\Telemetry\Metrics\Sample;

/**
 * Renders metric families to the Prometheus text exposition format.
 *
 * Names are converted from OTel dot notation to Prometheus underscores;
 * counters get the conventional `_total` suffix; histogram buckets are
 * accumulated into cumulative `le` buckets at render time.
 */
final class PrometheusRenderer
{
    public const MIME_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

    /**
     * @param  list<MetricFamily>  $families
     */
    public function render(array $families): string
    {
        $output = [];

        foreach ($families as $family) {
            $name = $family->definition->prometheusName();

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
                    array_push($output, ...$this->renderHistogram($name, $sample));
                } elseif ($sample instanceof Sample) {
                    $output[] = $name.$this->renderLabels($sample->labels).' '.$this->formatValue($sample->value);
                }
            }
        }

        return $output === [] ? '' : implode("\n", $output)."\n";
    }

    /**
     * @return list<string>
     */
    private function renderHistogram(string $name, HistogramSample $sample): array
    {
        $lines = [];
        $cumulative = 0;

        foreach ($sample->bounds as $index => $bound) {
            $cumulative += $sample->bucketCounts[$index] ?? 0;

            $lines[] = $name.'_bucket'.$this->renderLabels($sample->labels, ['le' => $this->formatValue($bound)]).' '.$cumulative;
        }

        $lines[] = $name.'_bucket'.$this->renderLabels($sample->labels, ['le' => '+Inf']).' '.$sample->count;
        $lines[] = $name.'_sum'.$this->renderLabels($sample->labels).' '.$this->formatValue($sample->sum);
        $lines[] = $name.'_count'.$this->renderLabels($sample->labels).' '.$sample->count;

        return $lines;
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array<string, string>  $extra
     */
    private function renderLabels(array $labels, array $extra = []): string
    {
        $all = [...$labels, ...$extra];

        if ($all === []) {
            return '';
        }

        $parts = [];

        foreach ($all as $key => $value) {
            $parts[] = $this->sanitizeLabelName($key).'="'.$this->escapeLabelValue($value).'"';
        }

        return '{'.implode(',', $parts).'}';
    }

    private function sanitizeLabelName(string $name): string
    {
        return (string) preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
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
