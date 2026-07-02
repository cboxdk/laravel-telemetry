<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Metrics\Stores;

use Cbox\Telemetry\Contracts\MetricStore;
use Cbox\Telemetry\Metrics\HistogramSample;
use Cbox\Telemetry\Metrics\Labels;
use Cbox\Telemetry\Metrics\MetricDefinition;
use Cbox\Telemetry\Metrics\MetricFamily;
use Cbox\Telemetry\Metrics\MetricType;
use Cbox\Telemetry\Metrics\Sample;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\Connection;

/**
 * The default shared store: one Redis HASH per metric family, one index SET
 * per metric type.
 *
 * Key layout (prefix "telemetry"):
 *
 *     telemetry:counter:{name}     HASH  field {labels-json} => float
 *     telemetry:gauge:{name}       HASH  field {labels-json} => float
 *     telemetry:histogram:{name}   HASH  field {b64-labels}:b{i} / :sum / :count
 *     telemetry:index:{type}       SET   member {name}
 *
 * Every hash carries a `__meta` field with the definition (idempotent
 * HSETNX — never conditional, so metadata cannot be lost under races).
 *
 * Collect is SMEMBERS + HGETALL per family — never KEYS or SCAN.
 * Writes run inside MULTI/EXEC so a series can never miss its index.
 */
final class RedisMetricStore implements MetricStore
{
    public function __construct(
        private readonly Factory $redis,
        private readonly string $connection = 'default',
        private readonly string $prefix = 'telemetry',
    ) {}

    public function incrementCounter(MetricDefinition $definition, array $labels, float $by): void
    {
        $key = $this->familyKey(MetricType::Counter, $definition->name);
        $field = Labels::encode($labels);
        $meta = $this->encodeMeta($definition);
        $index = $this->indexKey(MetricType::Counter);

        $this->transaction(function ($tx) use ($key, $field, $by, $meta, $index, $definition) {
            $tx->hincrbyfloat($key, $field, $by);
            $tx->hsetnx($key, '__meta', $meta);
            $tx->sadd($index, $definition->name);
        });
    }

    public function setGauge(MetricDefinition $definition, array $labels, float $value): void
    {
        $key = $this->familyKey(MetricType::Gauge, $definition->name);
        $field = Labels::encode($labels);
        $meta = $this->encodeMeta($definition);
        $index = $this->indexKey(MetricType::Gauge);

        $this->transaction(function ($tx) use ($key, $field, $value, $meta, $index, $definition) {
            $tx->hset($key, $field, (string) $value);
            $tx->hsetnx($key, '__meta', $meta);
            $tx->sadd($index, $definition->name);
        });
    }

    public function recordHistogram(MetricDefinition $definition, array $labels, float $value): void
    {
        $key = $this->familyKey(MetricType::Histogram, $definition->name);
        $series = base64_encode(Labels::encode($labels));
        $bounds = $definition->buckets ?? [];
        $bucket = $this->bucketIndex($bounds, $value);
        $meta = $this->encodeMeta($definition);
        $index = $this->indexKey(MetricType::Histogram);

        $this->transaction(function ($tx) use ($key, $series, $bucket, $value, $meta, $index, $definition) {
            $tx->hincrby($key, "{$series}:b{$bucket}", 1);
            $tx->hincrbyfloat($key, "{$series}:sum", $value);
            $tx->hincrby($key, "{$series}:count", 1);
            $tx->hsetnx($key, '__meta', $meta);
            $tx->sadd($index, $definition->name);
        });
    }

    public function collect(): array
    {
        $families = [];

        foreach ([MetricType::Counter, MetricType::Gauge] as $type) {
            foreach ($this->names($type) as $name) {
                $family = $this->collectScalarFamily($type, $name);

                if ($family !== null) {
                    $families[] = $family;
                }
            }
        }

        foreach ($this->names(MetricType::Histogram) as $name) {
            $family = $this->collectHistogramFamily($name);

            if ($family !== null) {
                $families[] = $family;
            }
        }

        return $families;
    }

    public function wipe(): void
    {
        $connection = $this->connection();

        foreach (MetricType::cases() as $type) {
            foreach ($this->names($type) as $name) {
                $connection->del($this->familyKey($type, $name));
            }

            $connection->del($this->indexKey($type));
        }
    }

    /**
     * @return list<string>
     */
    private function names(MetricType $type): array
    {
        /** @var list<string> $members */
        $members = $this->connection()->smembers($this->indexKey($type)) ?: [];

        sort($members);

        return $members;
    }

    private function collectScalarFamily(MetricType $type, string $name): ?MetricFamily
    {
        /** @var array<string, string> $fields */
        $fields = $this->connection()->hgetall($this->familyKey($type, $name)) ?: [];

        $definition = $this->decodeMeta($name, $type, $fields['__meta'] ?? null);

        if ($definition === null) {
            return null;
        }

        $samples = [];

        foreach ($fields as $field => $value) {
            if ($field === '__meta') {
                continue;
            }

            $samples[] = new Sample(Labels::decode($field), (float) $value);
        }

        return new MetricFamily($definition, $samples);
    }

    private function collectHistogramFamily(string $name): ?MetricFamily
    {
        /** @var array<string, string> $fields */
        $fields = $this->connection()->hgetall($this->familyKey(MetricType::Histogram, $name)) ?: [];

        $definition = $this->decodeMeta($name, MetricType::Histogram, $fields['__meta'] ?? null);

        if ($definition === null) {
            return null;
        }

        $bounds = $definition->buckets ?? [];
        $bucketSlots = count($bounds) + 1;

        /** @var array<string, array{bucketCounts: list<int>, sum: float, count: int}> $series */
        $series = [];

        foreach ($fields as $field => $value) {
            if ($field === '__meta') {
                continue;
            }

            $separator = strrpos($field, ':');

            if ($separator === false) {
                continue;
            }

            $encoded = substr($field, 0, $separator);
            $suffix = substr($field, $separator + 1);

            $series[$encoded] ??= [
                'bucketCounts' => array_fill(0, $bucketSlots, 0),
                'sum' => 0.0,
                'count' => 0,
            ];

            if ($suffix === 'sum') {
                $series[$encoded]['sum'] = (float) $value;
            } elseif ($suffix === 'count') {
                $series[$encoded]['count'] = (int) $value;
            } elseif (str_starts_with($suffix, 'b')) {
                $index = (int) substr($suffix, 1);

                if ($index < $bucketSlots) {
                    $series[$encoded]['bucketCounts'][$index] = (int) $value;
                }
            }
        }

        $samples = [];

        foreach ($series as $encoded => $data) {
            $samples[] = new HistogramSample(
                labels: Labels::decode(base64_decode($encoded, true) ?: '{}'),
                bounds: $bounds,
                bucketCounts: $data['bucketCounts'],
                sum: $data['sum'],
                count: $data['count'],
            );
        }

        return new MetricFamily($definition, $samples);
    }

    private function connection(): Connection
    {
        return $this->redis->connection($this->connection);
    }

    /**
     * Run commands inside MULTI/EXEC so a series can never miss its index
     * or metadata. Both phpredis and predis connections expose
     * transaction(); on predis it resolves through Connection::__call,
     * which static analysis cannot see.
     *
     * @param  \Closure(mixed): void  $commands
     */
    private function transaction(\Closure $commands): void
    {
        /** @phpstan-ignore method.notFound (resolved via Connection::__call on predis) */
        $this->connection()->transaction($commands);
    }

    private function familyKey(MetricType $type, string $name): string
    {
        return "{$this->prefix}:{$type->value}:{$name}";
    }

    private function indexKey(MetricType $type): string
    {
        return "{$this->prefix}:index:{$type->value}";
    }

    private function encodeMeta(MetricDefinition $definition): string
    {
        return json_encode([
            'description' => $definition->description,
            'unit' => $definition->unit,
            'buckets' => $definition->buckets,
        ], JSON_THROW_ON_ERROR);
    }

    private function decodeMeta(string $name, MetricType $type, ?string $meta): ?MetricDefinition
    {
        if ($meta === null) {
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
