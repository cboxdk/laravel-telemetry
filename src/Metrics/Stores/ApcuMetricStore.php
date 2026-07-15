<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Metrics\Stores;

use Cbox\Telemetry\Contracts\MetricStore;
use Cbox\Telemetry\Exceptions\ApcuUnavailable;
use Cbox\Telemetry\Metrics\Exemplar;
use Cbox\Telemetry\Metrics\HistogramSample;
use Cbox\Telemetry\Metrics\Labels;
use Cbox\Telemetry\Metrics\MetricDefinition;
use Cbox\Telemetry\Metrics\MetricFamily;
use Cbox\Telemetry\Metrics\MetricType;
use Cbox\Telemetry\Metrics\Sample;

/**
 * Single-node shared store backed by APCu.
 *
 * Maintains explicit indexes (metric names per type, series per metric) so
 * collect never iterates the full APCu keyspace — the classic APCuIterator
 * regex-over-everything scrape stall is designed out.
 *
 * Floats are stored as CAS-able integers via pack('d')/unpack('q').
 */
final class ApcuMetricStore implements MetricStore
{
    /** @var array<string, true> per-process memo of indexed series */
    private array $indexed = [];

    public function __construct(
        private readonly string $prefix = 'telemetry',
    ) {
        if (! extension_loaded('apcu') || ! apcu_enabled()) {
            throw new ApcuUnavailable;
        }
    }

    public function incrementCounter(MetricDefinition $definition, array $labels, float $by): void
    {
        $series = Labels::encode($labels);

        $this->index($definition, $series);
        $this->addFloat($this->valueKey(MetricType::Counter, $definition->name, $series), $by);
    }

    public function setGauge(MetricDefinition $definition, array $labels, float $value): void
    {
        $series = Labels::encode($labels);

        $this->index($definition, $series);
        apcu_store($this->valueKey(MetricType::Gauge, $definition->name, $series), $this->toInt($value));
    }

    public function addGauge(MetricDefinition $definition, array $labels, float $delta): void
    {
        $series = Labels::encode($labels);

        $this->index($definition, $series);
        $this->addFloat($this->valueKey(MetricType::Gauge, $definition->name, $series), $delta);
    }

    public function recordHistogram(MetricDefinition $definition, array $labels, float $value, ?Exemplar $exemplar = null): void
    {
        $series = Labels::encode($labels);
        $bounds = $definition->buckets ?? [];
        $bucket = $this->bucketIndex($bounds, $value);
        $base = $this->valueKey(MetricType::Histogram, $definition->name, $series);

        $this->index($definition, $series);

        $this->addInt("{$base}:b{$bucket}", 1);
        $this->addFloat("{$base}:sum", $value);
        $this->addInt("{$base}:count", 1);

        if ($exemplar !== null) {
            apcu_store("{$base}:exemplar", $this->encodeExemplar($exemplar));
        }
    }

    public function mergeHistogram(MetricDefinition $definition, array $labels, array $bucketCounts, float $sum, int $count, ?Exemplar $exemplar = null): void
    {
        $series = Labels::encode($labels);
        $base = $this->valueKey(MetricType::Histogram, $definition->name, $series);

        $this->index($definition, $series);

        foreach ($bucketCounts as $index => $bucketCount) {
            if ($bucketCount > 0) {
                $this->addInt("{$base}:b{$index}", $bucketCount);
            }
        }

        if ($sum !== 0.0) {
            $this->addFloat("{$base}:sum", $sum);
        }

        if ($count > 0) {
            $this->addInt("{$base}:count", $count);
        }

        if ($exemplar !== null) {
            apcu_store("{$base}:exemplar", $this->encodeExemplar($exemplar));
        }
    }

    private function encodeExemplar(Exemplar $exemplar): string
    {
        return json_encode([
            't' => $exemplar->traceId,
            'v' => $exemplar->value,
            'n' => $exemplar->timeUnixNano,
        ]) ?: '{}';
    }

    private function decodeExemplar(mixed $raw): ?Exemplar
    {
        if (! is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || ! is_string($decoded['t'] ?? null) || $decoded['t'] === '') {
            return null;
        }

        return new Exemplar(
            traceId: $decoded['t'],
            value: is_numeric($decoded['v'] ?? null) ? (float) $decoded['v'] : 0.0,
            timeUnixNano: is_numeric($decoded['n'] ?? null) ? (int) $decoded['n'] : 0,
        );
    }

    public function collect(): array
    {
        $families = [];

        foreach ([MetricType::Counter, MetricType::Gauge] as $type) {
            foreach ($this->names($type) as $name) {
                $definition = $this->definition($type, $name);

                if ($definition === null) {
                    continue;
                }

                $samples = [];

                foreach ($this->seriesOf($type, $name) as $series) {
                    $raw = apcu_fetch($this->valueKey($type, $name, $series));

                    if (! is_int($raw)) {
                        continue;
                    }

                    $samples[] = new Sample(Labels::decode($series), $this->toFloat($raw));
                }

                // Indexes survive a wipe; skip families with no data yet.
                if ($samples === []) {
                    continue;
                }

                $families[] = new MetricFamily($definition, $samples, $this->since($type, $name));
            }
        }

        foreach ($this->names(MetricType::Histogram) as $name) {
            $definition = $this->definition(MetricType::Histogram, $name);

            if ($definition === null) {
                continue;
            }

            $bounds = $definition->buckets ?? [];
            $bucketSlots = count($bounds) + 1;
            $samples = [];

            foreach ($this->seriesOf(MetricType::Histogram, $name) as $series) {
                $base = $this->valueKey(MetricType::Histogram, $name, $series);
                $bucketCounts = [];
                $sawData = false;

                for ($i = 0; $i < $bucketSlots; $i++) {
                    $count = apcu_fetch("{$base}:b{$i}");
                    $sawData = $sawData || is_int($count);
                    $bucketCounts[] = is_int($count) ? $count : 0;
                }

                $sum = apcu_fetch("{$base}:sum");
                $count = apcu_fetch("{$base}:count");

                // Series indexes survive a wipe; a series with no keys at
                // all has no data yet — nothing to report.
                if (! $sawData && ! is_int($sum) && ! is_int($count)) {
                    continue;
                }

                $samples[] = new HistogramSample(
                    labels: Labels::decode($series),
                    bounds: $bounds,
                    bucketCounts: $bucketCounts,
                    sum: is_int($sum) ? $this->toFloat($sum) : 0.0,
                    count: is_int($count) ? $count : 0,
                    exemplar: $this->decodeExemplar(apcu_fetch("{$base}:exemplar")),
                );
            }

            // Indexes survive a wipe; skip families with no data yet.
            if ($samples === []) {
                continue;
            }

            $families[] = new MetricFamily($definition, $samples, $this->since(MetricType::Histogram, $name));
        }

        return $families;
    }

    /**
     * Reset every value while PRESERVING meta and the name/series indexes.
     * Warm workers memoize index() per process and series — deleting the
     * bookkeeping would leave their subsequent writes invisible until every
     * process recycled. `since` is reset so cumulative start timestamps
     * restart at the wipe.
     */
    public function wipe(): void
    {
        $now = (int) (microtime(true) * 1e9);

        foreach (MetricType::cases() as $type) {
            foreach ($this->names($type) as $name) {
                // Delete a generous fixed range of bucket slots so bucket
                // keys are removed even when the meta entry is gone.
                $bucketSlots = max(64, count($this->definition($type, $name)->buckets ?? []) + 1);

                foreach ($this->seriesOf($type, $name) as $series) {
                    $base = $this->valueKey($type, $name, $series);

                    apcu_delete($base);
                    apcu_delete("{$base}:sum");
                    apcu_delete("{$base}:count");
                    apcu_delete("{$base}:exemplar");

                    for ($i = 0; $i < $bucketSlots; $i++) {
                        apcu_delete("{$base}:b{$i}");
                    }
                }

                apcu_store($this->sinceKey($type, $name), $now);
            }
        }
    }

    public function forgetSeries(MetricDefinition $definition, array $labels): void
    {
        $type = $definition->type;
        $series = Labels::encode($labels);
        $base = $this->valueKey($type, $definition->name, $series);

        apcu_delete($base);
        apcu_delete("{$base}:sum");
        apcu_delete("{$base}:count");
        apcu_delete("{$base}:exemplar");

        for ($i = 0, $slots = max(64, count($definition->buckets ?? []) + 1); $i < $slots; $i++) {
            apcu_delete("{$base}:b{$i}");
        }

        // Safe to drop from the series index: forgetSeries is only for
        // series no other live process writes to (see the contract).
        $this->removeUnique($this->seriesIndexKey($type, $definition->name), $series);
        unset($this->indexed["{$type->value}:{$definition->name}:{$series}"]);
    }

    private function since(MetricType $type, string $name): ?int
    {
        $since = apcu_fetch($this->sinceKey($type, $name));

        return is_int($since) ? $since : null;
    }

    /**
     * Register the metric name, definition and series in the explicit
     * indexes. Memoized per process — the steady-state hot path skips
     * this entirely. Meta uses apcu_store so definition changes (buckets,
     * description) propagate on deploy; `since` keeps the first write.
     */
    private function index(MetricDefinition $definition, string $series): void
    {
        $type = $definition->type;
        $memo = "{$type->value}:{$definition->name}:{$series}";

        if (isset($this->indexed[$memo])) {
            return;
        }

        $this->indexed[$memo] = true;

        apcu_store($this->metaKey($type, $definition->name), json_encode([
            'description' => $definition->description,
            'unit' => $definition->unit,
            'buckets' => $definition->buckets,
        ], JSON_THROW_ON_ERROR));

        apcu_add($this->sinceKey($type, $definition->name), (int) (microtime(true) * 1e9));

        $this->appendUnique($this->nameIndexKey($type), $definition->name);
        $this->appendUnique($this->seriesIndexKey($type, $definition->name), $series);
    }

    /**
     * Append a value to a list key if missing, guarded by a spin lock.
     */
    private function appendUnique(string $key, string $value): void
    {
        $current = apcu_fetch($key);

        if (is_array($current) && in_array($value, $current, true)) {
            return;
        }

        $lock = "{$key}:lock";

        for ($attempt = 0; $attempt < 100; $attempt++) {
            if (apcu_add($lock, 1, 1)) {
                $current = apcu_fetch($key);
                $current = is_array($current) ? $current : [];

                if (! in_array($value, $current, true)) {
                    $current[] = $value;
                    apcu_store($key, $current);
                }

                apcu_delete($lock);

                return;
            }

            usleep(100);
        }
    }

    /**
     * Remove a value from a list key if present, guarded by the same
     * spin lock as appendUnique.
     */
    private function removeUnique(string $key, string $value): void
    {
        $current = apcu_fetch($key);

        if (! is_array($current) || ! in_array($value, $current, true)) {
            return;
        }

        $lock = "{$key}:lock";

        for ($attempt = 0; $attempt < 100; $attempt++) {
            if (apcu_add($lock, 1, 1)) {
                $current = apcu_fetch($key);

                if (is_array($current)) {
                    $filtered = array_values(array_filter($current, static fn ($entry): bool => $entry !== $value));
                    apcu_store($key, $filtered);
                }

                apcu_delete($lock);

                return;
            }

            usleep(100);
        }
    }

    /**
     * @return list<string>
     */
    private function names(MetricType $type): array
    {
        $names = apcu_fetch($this->nameIndexKey($type));

        if (! is_array($names)) {
            return [];
        }

        sort($names);

        /** @var list<string> $names */
        return $names;
    }

    /**
     * @return list<string>
     */
    private function seriesOf(MetricType $type, string $name): array
    {
        $series = apcu_fetch($this->seriesIndexKey($type, $name));

        /** @var list<string> */
        return is_array($series) ? array_values($series) : [];
    }

    private function definition(MetricType $type, string $name): ?MetricDefinition
    {
        $meta = apcu_fetch($this->metaKey($type, $name));

        if (! is_string($meta)) {
            return null;
        }

        /** @var array{description?: string, unit?: string, buckets?: list<float|int>|null} $decoded */
        $decoded = json_decode($meta, true, flags: JSON_THROW_ON_ERROR);

        $buckets = $decoded['buckets'] ?? null;

        return new MetricDefinition(
            name: $name,
            type: $type,
            description: $decoded['description'] ?? '',
            unit: $decoded['unit'] ?? '',
            // JSON drops the zero fraction on round floats — restore floats.
            buckets: $buckets === null ? null : array_map(floatval(...), $buckets),
        );
    }

    private function addFloat(string $key, float $by): void
    {
        for ($attempt = 0; $attempt < 1000; $attempt++) {
            $old = apcu_fetch($key);

            if (! is_int($old)) {
                if (apcu_add($key, $this->toInt($by))) {
                    return;
                }

                continue;
            }

            if (apcu_cas($key, $old, $this->toInt($this->toFloat($old) + $by))) {
                return;
            }
        }
    }

    private function addInt(string $key, int $by): void
    {
        apcu_inc($key, $by);
    }

    private function toInt(float $value): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('q', pack('d', $value));

        return $unpacked[1];
    }

    private function toFloat(int $value): float
    {
        /** @var array{1: float} $unpacked */
        $unpacked = unpack('d', pack('q', $value));

        return $unpacked[1];
    }

    private function valueKey(MetricType $type, string $name, string $series): string
    {
        return "{$this->prefix}:{$type->value}:{$name}:".base64_encode($series);
    }

    private function metaKey(MetricType $type, string $name): string
    {
        return "{$this->prefix}:meta:{$type->value}:{$name}";
    }

    private function sinceKey(MetricType $type, string $name): string
    {
        return "{$this->prefix}:since:{$type->value}:{$name}";
    }

    private function nameIndexKey(MetricType $type): string
    {
        return "{$this->prefix}:names:{$type->value}";
    }

    private function seriesIndexKey(MetricType $type, string $name): string
    {
        return "{$this->prefix}:series:{$type->value}:{$name}";
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
