<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Metrics\Stores;

use Cbox\Telemetry\Contracts\MetricStore;
use Cbox\Telemetry\Metrics\Exemplar;
use Cbox\Telemetry\Metrics\HistogramSample;
use Cbox\Telemetry\Metrics\Labels;
use Cbox\Telemetry\Metrics\MetricDefinition;
use Cbox\Telemetry\Metrics\MetricFamily;
use Cbox\Telemetry\Metrics\MetricType;
use Cbox\Telemetry\Metrics\Sample;
use Cbox\Telemetry\Support\SharedState;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Throwable;

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
 * Bookkeeping (meta, since-timestamp, index membership) is written once
 * per process and metric — the steady-state hot path is a SINGLE atomic
 * command (HINCRBYFLOAT / HSET), which also keeps every operation valid
 * on Redis Cluster (no cross-slot transactions). Meta is refreshed by the
 * first write of each process, so definition changes propagate on deploy.
 *
 * Collect is SMEMBERS + HGETALL per family — never KEYS or SCAN.
 */
final class RedisMetricStore implements MetricStore
{
    /**
     * Applies a flat list of (op, field, value) triples to one hash.
     * `i` HINCRBY, `f` HINCRBYFLOAT, `s` HSET.
     */
    private const APPLY_SCRIPT = <<<'LUA'
        local i = 1
        while i <= #ARGV do
            local op = ARGV[i]
            if op == 'i' then
                redis.call('HINCRBY', KEYS[1], ARGV[i + 1], ARGV[i + 2])
            elseif op == 'f' then
                redis.call('HINCRBYFLOAT', KEYS[1], ARGV[i + 1], ARGV[i + 2])
            else
                redis.call('HSET', KEYS[1], ARGV[i + 1], ARGV[i + 2])
            end
            i = i + 3
        end
        return 1
        LUA;

    /** Whether this server runs EVAL. Set false once, if it does not. */
    private bool $scripting = true;

    public function __construct(
        private readonly Factory $redis,
        private readonly string $connection = 'default',
        private readonly string $prefix = 'telemetry',
    ) {}

    public function incrementCounter(MetricDefinition $definition, array $labels, float $by): void
    {
        $key = $this->familyKey(MetricType::Counter, $definition->name);

        $this->initialize($definition, $key);
        $this->connection()->hincrbyfloat($key, Labels::encode($labels), $by);
    }

    public function setGauge(MetricDefinition $definition, array $labels, float $value): void
    {
        // The DEFINITION's type, not a hardcoded Gauge. A push instrument
        // whose value is read whole rather than accumulated may still be
        // declared an up-down counter or a counter — a daemon reading a
        // host's memory writes an absolute number, and what that number
        // IS does not change because of how it reached the store. Keying
        // by the declared type is what lets it come back out as one.
        $key = $this->familyKey($this->scalarType($definition), $definition->name);

        $this->initialize($definition, $key);
        $this->connection()->hset($key, Labels::encode($labels), (string) $value);
    }

    public function addGauge(MetricDefinition $definition, array $labels, float $delta): void
    {
        $key = $this->familyKey($this->scalarType($definition), $definition->name);

        $this->initialize($definition, $key);
        $this->connection()->hincrbyfloat($key, Labels::encode($labels), $delta);
    }

    public function recordHistogram(MetricDefinition $definition, array $labels, float $value, ?Exemplar $exemplar = null): void
    {
        $key = $this->familyKey(MetricType::Histogram, $definition->name);
        $series = base64_encode(Labels::encode($labels));
        $bucket = $this->bucketIndex($definition->buckets ?? [], $value);

        $this->initialize($definition, $key);

        $operations = [
            ['i', "{$series}:b{$bucket}", '1'],
            ['f', "{$series}:sum", (string) $value],
            ['i', "{$series}:count", '1'],
        ];

        if ($exemplar !== null) {
            $operations[] = ['s', "{$series}:exemplar", $this->encodeExemplar($exemplar)];
        }

        $this->apply($key, $operations);
    }

    public function mergeHistogram(MetricDefinition $definition, array $labels, array $bucketCounts, float $sum, int $count, ?Exemplar $exemplar = null): void
    {
        $key = $this->familyKey(MetricType::Histogram, $definition->name);
        $series = base64_encode(Labels::encode($labels));

        $this->initialize($definition, $key);

        $operations = [];

        foreach ($bucketCounts as $index => $bucketCount) {
            if ($bucketCount > 0) {
                $operations[] = ['i', "{$series}:b{$index}", (string) $bucketCount];
            }
        }

        if ($sum !== 0.0) {
            $operations[] = ['f', "{$series}:sum", (string) $sum];
        }

        if ($count > 0) {
            $operations[] = ['i', "{$series}:count", (string) $count];
        }

        if ($exemplar !== null) {
            $operations[] = ['s', "{$series}:exemplar", $this->encodeExemplar($exemplar)];
        }

        $this->apply($key, $operations);
    }

    /**
     * Apply several field writes to ONE hash, atomically and in one
     * round trip.
     *
     * A histogram observation is three writes — bucket, sum, count —
     * and they were three commands. Two consequences, both of which
     * showed up in review. The cost: three synchronous round trips per
     * observation, so a request touching three histogram families paid
     * nine before anything else. And the correctness: a failure between
     * them leaves a series whose buckets add up to more than its count,
     * which is not a number anyone can reason about afterwards — a
     * retry after a partial write produced exactly that.
     *
     * Every field is in the same key, so the script is single-key and
     * valid on Redis Cluster. A server that refuses EVAL falls back to
     * the individual commands, once, and is remembered: an atomicity
     * improvement must not be able to stop metrics being written at
     * all.
     *
     * @param  list<array{0: 'i'|'f'|'s', 1: string, 2: string}>  $operations
     */
    private function apply(string $key, array $operations): void
    {
        if ($operations === []) {
            return;
        }

        $connection = $this->connection();

        if ($this->scripting) {
            $arguments = [];

            foreach ($operations as [$op, $field, $value]) {
                $arguments[] = $op;
                $arguments[] = $field;
                $arguments[] = $value;
            }

            try {
                // The two clients disagree on how EVAL is called.
                // phpredis takes (script, args, numKeys) and Laravel's
                // PhpRedisConnection::eval() reorders for it; predis
                // takes (script, numKeys, ...args) natively. Spelling
                // both out is shorter than the wrapper that would hide
                // it, and says which is which.
                if ($connection instanceof PhpRedisConnection) {
                    $connection->eval(self::APPLY_SCRIPT, 1, $key, ...$arguments);
                } else {
                    $connection->command('eval', [self::APPLY_SCRIPT, 1, $key, ...$arguments]);
                }

                return;
            } catch (Throwable) {
                $this->scripting = false;
            }
        }

        foreach ($operations as [$op, $field, $value]) {
            match ($op) {
                'i' => $connection->command('hincrby', [$key, $field, (int) $value]),
                'f' => $connection->command('hincrbyfloat', [$key, $field, (float) $value]),
                's' => $connection->command('hset', [$key, $field, $value]),
            };
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

    private function decodeExemplar(string $raw): ?Exemplar
    {
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

    /**
     * Write meta, since-timestamp and index membership once per process
     * and metric. Meta uses HSET (not HSETNX) so a deploy with changed
     * buckets/description refreshes it; `__since` keeps the first-ever
     * write time for OTLP cumulative start timestamps.
     */
    /**
     * How long a successful initialization is trusted before it is written
     * again. Three commands per metric per process per interval is far
     * cheaper than a metric silently disappearing until a deploy.
     */
    private const REINITIALIZE_AFTER_SECONDS = 300;

    private function initialize(MetricDefinition $definition, string $key): void
    {
        // Held in shared memory where there is any. A per-process memo
        // is no memo at all under PHP-FPM, where the process state is
        // the request's: every request rewrote the bookkeeping for
        // every metric it touched, three commands each, and with ten
        // families that is thirty round trips a request for data that
        // changes on deploy.
        $memo = 'store:init:'.$this->prefix.':'.$definition->type->value.':'.$definition->name;
        $now = time();

        // Re-run periodically rather than once per process. The bookkeeping
        // lives in Redis, and Redis can lose it in ways this package does not
        // control — a restart without persistence, a FLUSHDB, an eviction of
        // the index set under maxmemory. A process that had already memoized
        // "done" kept writing data nobody could read: collect() walks the
        // index and drops any family whose __meta is missing, so the metric
        // vanished from every scrape until a COLD process happened to write
        // it again — which, for a series with one long-lived writer, is never.
        if ($now < SharedState::deadline($memo)) {
            return;
        }

        $connection = $this->connection();
        $connection->hset($key, '__meta', $this->encodeMeta($definition));
        $connection->hsetnx($key, '__since', (string) ((int) (microtime(true) * 1e9)));
        $connection->sadd($this->indexKey($definition->type), $definition->name);

        // Memoize only once the writes landed. Setting it first meant a single
        // transient failure disabled initialization for the life of the
        // process, permanently.
        SharedState::remember($memo, $now + self::REINITIALIZE_AFTER_SECONDS);
    }

    public function collect(): array
    {
        $families = [];

        foreach ([MetricType::Counter, MetricType::UpDownCounter, MetricType::Gauge] as $type) {
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

    /**
     * Reset every value while PRESERVING `__meta` and the index sets.
     * Warm workers memoize initialize() per process — deleting the
     * bookkeeping would leave their subsequent writes invisible (no meta,
     * no index membership) until every process recycled. `__since` is
     * reset so cumulative start timestamps restart at the wipe.
     */
    public function wipe(): void
    {
        $connection = $this->connection();
        $now = (string) ((int) (microtime(true) * 1e9));

        foreach (MetricType::cases() as $type) {
            foreach ($this->names($type) as $name) {
                $key = $this->familyKey($type, $name);

                /** @var list<string> $fields */
                $fields = $connection->hkeys($key) ?: [];
                $values = array_values(array_filter(
                    $fields,
                    static fn (string $field): bool => $field !== '__meta',
                ));

                if ($values !== []) {
                    $connection->hdel($key, ...$values);
                }

                $connection->hset($key, '__since', $now);
            }
        }
    }

    public function forgetSeries(MetricDefinition $definition, array $labels): void
    {
        $key = $this->familyKey($definition->type, $definition->name);
        $connection = $this->connection();

        if ($definition->type === MetricType::Histogram) {
            $series = base64_encode(Labels::encode($labels));
            $fields = ["{$series}:sum", "{$series}:count", "{$series}:exemplar"];

            for ($i = 0, $slots = count($definition->buckets ?? []) + 1; $i < $slots; $i++) {
                $fields[] = "{$series}:b{$i}";
            }

            $connection->hdel($key, ...$fields);

            return;
        }

        $connection->hdel($key, Labels::encode($labels));
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
            if (str_starts_with($field, '__')) {
                continue;
            }

            $samples[] = new Sample(Labels::decode($field), (float) $value);
        }

        // A family can be all bookkeeping right after a wipe — nothing to
        // render until the next write.
        if ($samples === []) {
            return null;
        }

        return new MetricFamily($definition, $samples, $this->since($fields));
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

        /** @var array<string, array{bucketCounts: list<int>, sum: float, count: int, exemplar: Exemplar|null}> $series */
        $series = [];

        foreach ($fields as $field => $value) {
            if (str_starts_with($field, '__')) {
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
                'exemplar' => null,
            ];

            if ($suffix === 'sum') {
                $series[$encoded]['sum'] = (float) $value;
            } elseif ($suffix === 'count') {
                $series[$encoded]['count'] = (int) $value;
            } elseif ($suffix === 'exemplar') {
                $series[$encoded]['exemplar'] = $this->decodeExemplar($value);
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
                exemplar: $data['exemplar'],
            );
        }

        // A family can be all bookkeeping right after a wipe — nothing to
        // render until the next write.
        if ($samples === []) {
            return null;
        }

        return new MetricFamily($definition, $samples, $this->since($fields));
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function since(array $fields): ?int
    {
        return isset($fields['__since']) ? (int) $fields['__since'] : null;
    }

    private function connection(): Connection
    {
        return $this->redis->connection($this->connection);
    }

    /**
     * The storage namespace for a scalar push write. Histogram
     * definitions never reach the gauge writers, but a wrong definition
     * must not land histogram data in a scalar family.
     */
    private function scalarType(MetricDefinition $definition): MetricType
    {
        return $definition->type === MetricType::Histogram ? MetricType::Gauge : $definition->type;
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
