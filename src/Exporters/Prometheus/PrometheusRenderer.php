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

        foreach ($this->deduplicate($families, $openMetrics) as $family) {
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
                // OpenMetrics allows the UNIT metadata line and requires it to
                // agree with the name's suffix when present. Emitting it makes
                // the unit machine-readable instead of only living in the name.
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
    private function deduplicate(array $families, bool $openMetrics): array
    {
        /** @var array<string, MetricFamily> $byKey */
        $byKey = [];
        /** @var array<string, string> $ownerOf name => key that already writes it */
        $ownerOf = [];

        foreach ($families as $family) {
            $names = $this->occupiedNames($family, $openMetrics);
            $key = implode("\0", $names);
            $existing = $byKey[$key] ?? null;

            if ($existing === null) {
                // Every name this family will write must be free. A histogram
                // `payload` writes payload_bucket/_sum/_count, so a gauge
                // `payload.count` collides with it even though neither of their
                // own "rendered names" match — and a counter in the classic
                // format writes only `<name>_total`, so it does NOT collide
                // with a gauge of the bare name, though it would in
                // OpenMetrics, where its metadata drops the suffix.
                $taken = array_intersect($names, array_keys($ownerOf));

                if ($taken !== []) {
                    // Emitting both would declare one name twice and fail the
                    // scrape for every metric. First one wins, as on a type
                    // conflict.
                    continue;
                }

                // Deduplicate WITHIN the family too. Two labelsets that differ
                // only in spelling or key order — `host.name` and `host_name`,
                // or the same keys written in another order by a different
                // process — render as one series, and a family that never got
                // merged with another was previously never checked at all.
                $byKey[$key] = new MetricFamily(
                    $family->definition,
                    $this->mergeSamples([], $family->samples),
                    $family->startUnixNano,
                );

                foreach ($names as $name) {
                    $ownerOf[$name] = $key;
                }

                continue;
            }

            // Same type is not enough: `latency` in seconds and
            // `latency_seconds` in microseconds render to one name, and
            // merging them would report microseconds under a seconds suffix.
            if ($existing->type() === $family->type()
                && $existing->definition->unit === $family->definition->unit) {
                $byKey[$key] = new MetricFamily(
                    $existing->definition,
                    $this->mergeSamples($existing->samples, $family->samples),
                    $existing->startUnixNano ?? $family->startUnixNano,
                );
            }
        }

        return array_values($byKey);
    }

    /**
     * Every name this family will write, metadata and samples alike.
     *
     * @return list<string>
     */
    private function occupiedNames(MetricFamily $family, bool $openMetrics): array
    {
        $base = $this->familyName($family);

        return match ($family->type()) {
            // Classic: metadata and samples both under `<name>_total`.
            // OpenMetrics: metadata under `<name>`, samples under `<name>_total`.
            MetricType::Counter => $openMetrics ? [$base, $base.'_total'] : [$base.'_total'],
            MetricType::Histogram => [$base, $base.'_bucket', $base.'_sum', $base.'_count'],
            default => [$base],
        };
    }

    /**
     * Merge two families' samples, keeping ONE per labelset.
     *
     * Concatenating them meant a stored push gauge and a cross-process
     * observable sharing a name AND a labelset produced two identical series
     * lines. Prometheus rejects the duplicate sample and carries on rather
     * than failing the scrape, so this one costs a silently dropped value, not
     * the target — unlike the duplicate FAMILY and LABEL cases above, which do
     * take everything down.
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
