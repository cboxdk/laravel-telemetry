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
            $name = $this->renderedName($family);

            // In OpenMetrics a Counter MetricFamily's name MUST NOT carry the
            // `_total` suffix — the SAMPLE carries it, the family does not.
            // Emitting `# TYPE foo_total counter` registers the metadata under
            // a name no metric has, so Prometheus' UI and metadata API show
            // none for `foo`, and strict OpenMetrics consumers reject it.
            $familyName = $openMetrics ? $this->familyName($family) : $name;

            $help = $family->definition->description;

            if ($family->definition->unit !== '') {
                $help = trim("{$help} (unit: {$family->definition->unit})");
            }

            if ($help !== '') {
                $output[] = '# HELP '.$familyName.' '.$this->escapeHelp($help);
            }

            $output[] = '# TYPE '.$familyName.' '.$family->type()->value;

            if ($openMetrics && $family->definition->unit !== '' && $this->unitSuffix($family->definition->unit) !== '') {
                // OpenMetrics requires the UNIT metadata line when the name
                // carries a unit suffix, and it must agree with that suffix.
                $output[] = '# UNIT '.$familyName.' '.ltrim($this->unitSuffix($family->definition->unit), '_');
            }

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
            // Key on the RENDERED name, not the OTel one. MetricDefinition
            // allows `_` in an OTel name, so `orders.created` and
            // `orders_created` are two legal, distinct families that both
            // render as `orders_created_total` — they passed this dedupe and
            // then emitted two `# HELP`/`# TYPE` blocks for one name. A
            // Prometheus parse error fails the WHOLE scrape, so one such
            // collision takes every metric from the app down with it.
            // Key on BOTH names a family occupies. A counter writes samples as
            // `jobs_total` but its OpenMetrics metadata as `jobs`, so keying on
            // the sample name alone let a counter `jobs` and a gauge `jobs`
            // through — and they then emitted `# TYPE jobs counter` AND
            // `# TYPE jobs gauge`, one family name declared twice.
            $key = $this->renderedName($family)."\0".$this->familyName($family);
            $existing = $byName[$key] ?? null;

            if ($existing === null) {
                $collision = $this->collidingKey($byName, $family);

                if ($collision !== null) {
                    // Two families that cannot be merged but would occupy the
                    // same name. Emitting both fails the scrape for everything;
                    // the first one wins, as it does on a type conflict.
                    continue;
                }

                $byName[$key] = $family;

                continue;
            }

            // Same type is not enough: `latency` in seconds and
            // `latency_seconds` in microseconds render to one name, and
            // merging them would report microseconds under a seconds suffix.
            if ($existing->type() === $family->type()
                && $existing->definition->unit === $family->definition->unit) {
                $byName[$key] = new MetricFamily(
                    $existing->definition,
                    $this->mergeSamples($existing->samples, $family->samples),
                    $existing->startUnixNano ?? $family->startUnixNano,
                );
            }
        }

        return array_values($byName);
    }

    /**
     * Whether some already-kept family would render under one of this one's
     * two names — the sample name or the metadata name.
     *
     * @param  array<string, MetricFamily>  $byName
     */
    private function collidingKey(array $byName, MetricFamily $family): ?string
    {
        foreach ($byName as $key => $kept) {
            if ($this->renderedName($kept) === $this->renderedName($family)
                || $this->familyName($kept) === $this->familyName($family)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Merge two families' samples, keeping ONE per labelset.
     *
     * Concatenating them meant a stored push gauge and a cross-process
     * observable sharing a name AND a labelset produced two identical series
     * lines — `duplicate sample`, and again the whole scrape fails rather than
     * the one metric.
     *
     * @param  list<Sample|HistogramSample>  $existing
     * @param  list<Sample|HistogramSample>  $incoming
     * @return list<Sample|HistogramSample>
     */
    private function mergeSamples(array $existing, array $incoming): array
    {
        $byLabels = [];

        foreach ([...$existing, ...$incoming] as $sample) {
            // Compare what will be WRITTEN, not what was stored: two samples
            // whose keys differ only in spelling or order render as one series.
            $byLabels[json_encode($this->canonicalLabels($sample->labels)) ?: ''] ??= $sample;
        }

        return array_values($byLabels);
    }

    /**
     * The family name for metadata lines. Identical to the sample name except
     * for an OpenMetrics counter, whose family name drops `_total`.
     */
    private function familyName(MetricFamily $family): string
    {
        return $family->definition->prometheusName().$this->unitSuffix($family->definition->unit);
    }

    /**
     * The name this family will actually be written as.
     */
    private function renderedName(MetricFamily $family): string
    {
        $name = $family->definition->prometheusName().$this->unitSuffix($family->definition->unit);

        return $family->type() === MetricType::Counter ? $name.'_total' : $name;
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
        $all = $this->canonicalLabels($labels, $extra);

        if ($all === []) {
            return '';
        }

        $rendered = [];

        foreach ($all as $name => $value) {
            $rendered[] = $name.'="'.$this->escapeLabelValue($value).'"';
        }

        return '{'.implode(',', $rendered).'}';
    }

    /**
     * The labels as Prometheus will see them: sanitized, then merged, then
     * ordered — so two spellings of one name cannot both survive, and
     * precedence does not depend on which of them happened to appear first.
     *
     * Sanitizing AFTER the merge meant a user label `host.name` — the dotted
     * style the docs recommend — alongside the pre-sanitized `host_name`
     * resource label were two distinct array keys that rendered as
     * `{host_name="web-1",host_name="db-3"}`. The Go text parser rejects
     * duplicate label names, and a parse error fails the WHOLE scrape: the
     * target goes up=0 and every metric from the app disappears.
     *
     * Sorting matters too: the same labelset arriving in a different key order
     * — a stored gauge versus a cross-process observable — would otherwise
     * render as two different series lines for one series.
     *
     * @param  array<string, string>  $labels
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function canonicalLabels(array $labels, array $extra = []): array
    {
        $merged = [];

        // Later sources win, and each is normalized before it is applied so
        // precedence never depends on the presence of an earlier spelling.
        foreach ([$this->resourceLabels, $labels, $extra] as $source) {
            foreach ($source as $key => $value) {
                // Array keys may be ints (json_decode of numeric label names).
                $merged[$this->sanitizeLabelName((string) $key)] = $value;
            }
        }

        ksort($merged);

        return $merged;
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
