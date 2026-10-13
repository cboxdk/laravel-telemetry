<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Metrics\Stores;

use Cbox\Telemetry\Contracts\BatchesWrites;
use Cbox\Telemetry\Contracts\ClaimsMetricExport;
use Cbox\Telemetry\Contracts\MetricStore;
use Cbox\Telemetry\Contracts\ReportsOverflow;
use Cbox\Telemetry\Metrics\Exemplar;
use Cbox\Telemetry\Metrics\HistogramSample;
use Cbox\Telemetry\Metrics\Labels;
use Cbox\Telemetry\Metrics\MetricDefinition;
use Cbox\Telemetry\Metrics\MetricFamily;
use Cbox\Telemetry\Metrics\MetricType;
use Cbox\Telemetry\Metrics\Sample;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\Support\SharedState;
use Closure;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisClusterConnection;
use Predis\Client;
use Predis\Pipeline\Pipeline;
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
final class RedisMetricStore implements BatchesWrites, ClaimsMetricExport, MetricStore, ReportsOverflow
{
    /**
     * Applies a flat list of (op, field, value) triples to one hash.
     * `i` HINCRBY, `f` HINCRBYFLOAT, `s` HSET.
     */
    private const APPLY_SCRIPT = <<<'LUA'
        local limit = tonumber(ARGV[1])
        local i = 2

        -- The budget is decided for the WHOLE observation, never per
        -- field. A histogram writes a bucket, a sum and a count; if the
        -- bucket is refused and the other two are not, the series ends
        -- up with a count of 2 and buckets adding to 1, which is not a
        -- number anyone can read. Either all of it lands or none of it
        -- does, and the refusal is counted once.
        if limit > 0 then
            local needed = 0
            while i <= #ARGV do
                if redis.call('HEXISTS', KEYS[1], ARGV[i + 1]) == 0 then
                    needed = needed + 1
                end
                i = i + 3
            end

            if needed > 0 and redis.call('HLEN', KEYS[1]) + needed > limit then
                redis.call('HINCRBY', KEYS[1], '__overflow', 1)
                return 1
            end
        end

        i = 2
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

        return 0
        LUA;

    /** How long a server that refused EVAL is left alone before we ask again. */
    private const RETRY_SCRIPTING_AFTER_SECONDS = 300;

    /**
     * Writes queued while batch() runs, sent together at its end. Null
     * outside a batch, where every write is its own round trip.
     *
     * @var list<array{0: 'eval', 1: string, 2: list<string>}|array{0: 'hset'|'hsetnx', 1: string, 2: string, 3: string}|array{0: 'sadd', 1: string, 2: string}>|null
     */
    private ?array $batched = null;

    /**
     * Bookkeeping memos to set once the batch carrying their writes has
     * landed — the same "memoize only once the writes landed" rule as the
     * unbatched path.
     *
     * @var array<string, int>
     */
    private array $batchedMemos = [];

    public function __construct(
        private readonly Factory $redis,
        private readonly string $connection = 'default',
        private readonly string $prefix = 'telemetry',
        private readonly int $maxFields = 50_000,
    ) {}

    /**
     * Send every write $writes produces in one pipeline.
     *
     * A request's flush touches a few dozen series, and each series was
     * an EVAL with its own round trip; behind an API request that was
     * most of what telemetry cost against a Redis over the network. In
     * one pipeline the whole flush is one round trip, and the script
     * per series stays single-key, so nothing here needs Redis Cluster
     * to accept a cross-slot transaction.
     *
     * The connection is opened before anything is queued on the wire,
     * so a Redis that cannot be reached throws here with nothing
     * applied, and the caller keeps its buffer. Past that point the
     * pipeline may have partly landed, so a failure is reported and the
     * batch dropped rather than thrown: a retry would count what landed
     * twice.
     */
    public function batch(Closure $writes): void
    {
        // Nested, or on a server that refuses EVAL: the writes go out
        // one by one exactly as they would without a batch — the
        // fallback path is per command and cannot be pipelined as one.
        if ($this->batched !== null || $this->scriptingIsRefused()) {
            $writes();

            return;
        }

        $connection = $this->connection();
        $redis = $connection->client();

        // A pipeline needs one server: phpredis' RedisCluster has none,
        // and a cluster would split it by slot anyway. Those keep the
        // per-command path.
        if (! ($redis instanceof \Redis || $redis instanceof Client)
            || $connection instanceof PhpRedisClusterConnection
            || $connection instanceof PredisClusterConnection) {
            $writes();

            return;
        }

        $this->batched = [];
        $this->batchedMemos = [];

        try {
            $writes();
            $commands = $this->batched ?? [];
            $memos = $this->batchedMemos;
        } finally {
            $this->batched = null;
            $this->batchedMemos = [];
        }

        if ($commands === []) {
            return;
        }

        // Predis connects lazily; phpredis already did when the
        // connection was built. Either way an unreachable server throws
        // here, before a single command is sent.
        if ($redis instanceof Client) {
            $redis->connect();
        }

        try {
            $evalRefused = $redis instanceof \Redis
                ? $this->sendWithPhpRedis($redis, $commands)
                : $this->sendWithPredis($redis, $commands);
        } catch (Throwable $e) {
            FailSafe::guard(static function () use ($e): never {
                throw $e;
            });

            return;
        }

        foreach ($memos as $memo => $until) {
            SharedState::remember($memo, $until);
        }

        // phpredis answers a refused EVAL with false rather than an
        // exception. A batch where that happened is gone, but the next
        // flush must take the per-command path, so ask once — the same
        // probe the unbatched path uses.
        if ($evalRefused && $this->scriptingIsRefusedByProbe($connection, $commands[0][1])) {
            SharedState::remember($this->scriptingKey(), time() + self::RETRY_SCRIPTING_AFTER_SECONDS);
        }
    }

    /**
     * @param  non-empty-list<array{0: 'eval', 1: string, 2: list<string>}|array{0: 'hset'|'hsetnx', 1: string, 2: string, 3: string}|array{0: 'sadd', 1: string, 2: string}>  $commands
     * @return bool whether a script came back false (scripting refused)
     */
    private function sendWithPhpRedis(\Redis $redis, array $commands): bool
    {
        $pipe = $redis->pipeline();

        if (! $pipe instanceof \Redis) {
            throw new \RuntimeException('phpredis did not open a pipeline.');
        }

        foreach ($commands as $command) {
            match ($command[0]) {
                // phpredis takes (script, args, numKeys), as in apply().
                'eval' => $pipe->eval(self::APPLY_SCRIPT, [$command[1], ...$command[2]], 1),
                'hset' => $pipe->hSet($command[1], $command[2], $command[3]),
                'hsetnx' => $pipe->hSetNx($command[1], $command[2], $command[3]),
                'sadd' => $pipe->sAdd($command[1], $command[2]),
            };
        }

        $results = $pipe->exec();

        return is_array($results) && in_array(false, $results, true);
    }

    /**
     * @param  non-empty-list<array{0: 'eval', 1: string, 2: list<string>}|array{0: 'hset'|'hsetnx', 1: string, 2: string, 3: string}|array{0: 'sadd', 1: string, 2: string}>  $commands
     * @return bool always false: predis raises a refused script as an exception
     */
    private function sendWithPredis(Client $client, array $commands): bool
    {
        $client->pipeline(function (Pipeline $pipe) use ($commands): void {
            foreach ($commands as $command) {
                match ($command[0]) {
                    // predis takes (script, numKeys, ...args), as in apply().
                    'eval' => $pipe->eval(self::APPLY_SCRIPT, 1, $command[1], ...$command[2]),
                    'hset' => $pipe->hset($command[1], $command[2], $command[3]),
                    'hsetnx' => $pipe->hsetnx($command[1], $command[2], $command[3]),
                    'sadd' => $pipe->sadd($command[1], [$command[2]]),
                };
            }
        });

        return false;
    }

    public function incrementCounter(MetricDefinition $definition, array $labels, float $by): void
    {
        $key = $this->familyKey(MetricType::Counter, $definition->name);

        $this->initialize($definition, $key);
        $this->apply($key, [['f', Labels::encode($labels), (string) $by]]);
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
        $this->apply($key, [['s', Labels::encode($labels), (string) $value]]);
    }

    public function addGauge(MetricDefinition $definition, array $labels, float $delta): void
    {
        $key = $this->familyKey($this->scalarType($definition), $definition->name);

        $this->initialize($definition, $key);
        $this->apply($key, [['f', Labels::encode($labels), (string) $delta]]);
    }

    public function recordHistogram(MetricDefinition $definition, array $labels, float $value, ?Exemplar $exemplar = null): void
    {
        // Belt and braces with Histogram::record(): Redis refuses to
        // increment a sum by NAN or INF, which fails the script after
        // the bucket has been counted.
        if (! is_finite($value)) {
            return;
        }

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
        // A sum can reach infinity from observations that were each
        // perfectly finite — two of PHP_FLOAT_MAX is all it takes, and
        // the write buffer adds them up before anything here sees
        // them. Redis then refuses the sum after the buckets have
        // already been counted.
        if (! is_finite($sum)) {
            return;
        }

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
     * The script also enforces the per-family field budget, which is
     * the only place a budget can be enforced correctly: the series
     * already stored live in Redis, so no process can know the count
     * on its own, and under PHP-FPM a per-process tally is a
     * per-request one. Inside the script HEXISTS and HLEN are local
     * calls — the budget costs nothing on the wire. Refused writes are
     * counted into a `__overflow` field on the family, because a
     * cardinality ceiling nobody is told about is just missing data.
     *
     * @param  list<array{0: 'i'|'f'|'s', 1: string, 2: string}>  $operations
     */
    private function apply(string $key, array $operations): void
    {
        if ($operations === []) {
            return;
        }

        $arguments = [(string) $this->maxFields];

        foreach ($operations as [$op, $field, $value]) {
            $arguments[] = $op;
            $arguments[] = $field;
            $arguments[] = $value;
        }

        // In a batch the script is queued and sent with the rest of the
        // flush; batch() only batches where scripting is accepted.
        if ($this->batched !== null) {
            $this->batched[] = ['eval', $key, $arguments];

            return;
        }

        $connection = $this->connection();

        if (! $this->scriptingIsRefused()) {
            try {
                // The two clients disagree on how EVAL is called.
                // phpredis takes (script, args, numKeys) and Laravel's
                // PhpRedisConnection::eval() reorders for it; predis
                // takes (script, numKeys, ...args) natively. Spelling
                // both out is shorter than the wrapper that would hide
                // it, and says which is which.
                $result = $connection instanceof PhpRedisConnection
                    ? $connection->eval(self::APPLY_SCRIPT, 1, $key, ...$arguments)
                    : $connection->command('eval', [self::APPLY_SCRIPT, 1, $key, ...$arguments]);

                // phpredis answers a refused command with false rather
                // than an exception, and the script itself always
                // returns an integer. Taking false for success meant a
                // server with scripting disabled silently discarded
                // every metric write, forever.
                // phpredis answers BOTH a refused command and a Lua
                // runtime error with false, so false alone does not
                // say which. A one-line probe does, and asking is the
                // only way to avoid the two wrong answers: repeating
                // the operations after a script that actually ran
                // double-counts, and latching off scripting for a
                // transient error throws away atomicity and the
                // series budget with it.
                if ($result === false) {
                    if (! $this->scriptingIsRefusedByProbe($connection, $key)) {
                        return;
                    }

                    SharedState::remember($this->scriptingKey(), time() + self::RETRY_SCRIPTING_AFTER_SECONDS);
                } else {
                    return;
                }
            } catch (Throwable $e) {
                // Only a server that cannot run EVAL justifies the
                // non-atomic path. A timeout does not: the script may
                // have executed and lost its reply, so repeating the
                // operations would count the observation twice — and
                // one lost observation is cheaper than a wrong one.
                if (! self::scriptingIsUnsupported($e)) {
                    return;
                }

                // Remembered with an expiry, not latched for the life
                // of the process: a transient answer must not disable
                // atomicity and the series budget permanently.
                SharedState::remember($this->scriptingKey(), time() + self::RETRY_SCRIPTING_AFTER_SECONDS);
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
        // Keyed by the connection AND the definition's shape, not just
        // its name. Two connections share a process; the same metric on
        // connection B would otherwise skip its own bookkeeping because
        // A had written its, and stay invisible to collection for five
        // minutes. A deploy that changes a histogram's bounds is the
        // same problem in time rather than space.
        $memo = 'store:init:'.$this->prefix.':'.$this->connection.':'
            .$definition->type->value.':'.$definition->name.':'
            .substr(hash('xxh128', $this->encodeMeta($definition)), 0, 12);
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

        // In a batch the bookkeeping rides in the same pipeline as the
        // writes, once per family however many of its series the flush
        // carries, and the memo waits until the batch has landed.
        if ($this->batched !== null) {
            if (! isset($this->batchedMemos[$memo])) {
                $this->batched[] = ['hset', $key, '__meta', $this->encodeMeta($definition)];
                $this->batched[] = ['hsetnx', $key, '__since', (string) ((int) (microtime(true) * 1e9))];
                $this->batched[] = ['sadd', $this->indexKey($definition->type), $definition->name];
                $this->batchedMemos[$memo] = $now + self::REINITIALIZE_AFTER_SECONDS;
            }

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

    private function scriptingKey(): string
    {
        return 'store:noscript:'.$this->prefix.':'.$this->connection;
    }

    /**
     * Ask the server whether it runs scripts at all.
     *
     * One trivial EVAL. If that works, the failure above was the
     * script's own — a NaN increment, a wrong type — and the fallback
     * would repeat work that already happened.
     */
    private function scriptingIsRefusedByProbe(Connection $connection, string $key): bool
    {
        try {
            // One key, like the real script. A managed Redis can
            // refuse a zero-key EVAL while running keyed ones
            // perfectly well — ElastiCache documents exactly that —
            // and a probe that does not look like the thing it is
            // probing for answers the wrong question.
            $probe = $connection instanceof PhpRedisConnection
                ? $connection->eval('return 1', 1, $key)
                : $connection->command('eval', ['return 1', 1, $key]);

            return $probe === false;
        } catch (Throwable $e) {
            return self::scriptingIsUnsupported($e);
        }
    }

    private function scriptingIsRefused(): bool
    {
        return time() < SharedState::deadline($this->scriptingKey());
    }

    /**
     * Whether this failure says the SERVER will not run scripts, as
     * opposed to saying nothing useful about whether it ran this one.
     */
    private static function scriptingIsUnsupported(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        // An ACL failure INSIDE the script is not a refused EVAL: the
        // script ran, some of its commands landed, and repeating them
        // non-atomically double-counts what already happened. Redis
        // says which by prefixing the in-script form.
        if (str_contains($message, 'in script') || str_contains($message, 'called from script')) {
            return false;
        }

        foreach (['unknown command', 'not allowed', 'unsupported', 'disabled', 'noperm', 'no permissions'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, int>
     */
    public function overflowingFamilies(): array
    {
        $overflowing = [];
        $connection = $this->connection();

        foreach (MetricType::cases() as $type) {
            foreach ($this->names($type) as $name) {
                $refused = $connection->hget($this->familyKey($type, $name), '__overflow');

                if (is_string($refused) && (int) $refused > 0) {
                    $overflowing[$name] = (int) $refused;
                }
            }
        }

        return $overflowing;
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

                // Only the slots array_fill() made: a stray `b-1` field would
                // otherwise add a key and the counts would stop being a list.
                if ($index >= 0 && $index < $bucketSlots) {
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

    /**
     * SET NX with an expiry: one caller per window gets it, and a claimer
     * that dies takes nothing with it — the claim lapses on its own.
     */
    public function claimExport(int $seconds): bool
    {
        $connection = $this->connection();
        $key = $this->prefix.':export-claim';
        $holder = gethostname() ?: 'unknown';

        $claimed = $connection instanceof PhpRedisConnection
            ? $connection->set($key, $holder, 'EX', max(1, $seconds), 'NX')
            : $connection->command('set', [$key, $holder, 'EX', max(1, $seconds), 'NX']);

        // phpredis answers true/false, predis an OK status or null.
        return $claimed !== false && $claimed !== null;
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
