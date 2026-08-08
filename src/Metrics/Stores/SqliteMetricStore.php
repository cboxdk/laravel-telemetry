<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Metrics\Stores;

use Cbox\Telemetry\Contracts\MetricStore;
use Cbox\Telemetry\Exceptions\SqliteUnavailable;
use Cbox\Telemetry\Metrics\Exemplar;
use Cbox\Telemetry\Metrics\HistogramSample;
use Cbox\Telemetry\Metrics\Labels;
use Cbox\Telemetry\Metrics\MetricDefinition;
use Cbox\Telemetry\Metrics\MetricFamily;
use Cbox\Telemetry\Metrics\MetricType;
use Cbox\Telemetry\Metrics\Sample;
use Cbox\Telemetry\Support\SqliteConnection;
use PDO;
use PDOStatement;

/**
 * File-backed store for runtimes with no Redis and no APCu — NativePHP
 * mobile (one embedded process, state must survive the app being killed)
 * and NativePHP desktop (server plus queue worker and scheduler writing
 * the same series from separate processes). See
 * docs/decisions/0001-metric-state-on-single-process-runtimes.md.
 *
 * Concurrency is SQLite's: WAL so a scrape never blocks a write, and a
 * busy timeout so a contended write waits instead of failing. Every write
 * is a single UPSERT, so concurrent increments accumulate rather than
 * overwrite.
 *
 * The connection is a raw PDO handle, deliberately NOT one of the app's
 * database connections — routing it through the DB manager would put
 * telemetry's own writes in front of QueryInstrumentation and metrics
 * would start measuring themselves.
 *
 * Rows are the index: collect walks the series table, never a directory
 * or a key pattern (invariant #2).
 */
final class SqliteMetricStore implements MetricStore
{
    private ?PDO $pdo = null;

    /** @var array<string, PDOStatement> prepared statement cache */
    private array $statements = [];

    /** @var array<string, true> per-process memo of written meta rows */
    private array $meta = [];

    public function __construct(
        private readonly string $path,
        private readonly int $busyTimeoutMs = 5000,
    ) {
        // Eagerly, like the APCu store: a missing extension is a wiring
        // mistake, and the connection itself is opened lazily — inside
        // FailSafe, where a late throw would be swallowed into silence.
        if (! extension_loaded('pdo_sqlite')) {
            throw new SqliteUnavailable;
        }
    }

    public function incrementCounter(MetricDefinition $definition, array $labels, float $by): void
    {
        $this->writeMeta($definition);

        $this->run(
            'INSERT INTO telemetry_metric_series (type, name, series, value) VALUES (?, ?, ?, ?)
             ON CONFLICT(type, name, series) DO UPDATE SET value = value + excluded.value',
            [MetricType::Counter->value, $definition->name, Labels::encode($labels), $by],
        );
    }

    public function setGauge(MetricDefinition $definition, array $labels, float $value): void
    {
        $this->writeMeta($definition);

        $this->run(
            'INSERT INTO telemetry_metric_series (type, name, series, value) VALUES (?, ?, ?, ?)
             ON CONFLICT(type, name, series) DO UPDATE SET value = excluded.value',
            [MetricType::Gauge->value, $definition->name, Labels::encode($labels), $value],
        );
    }

    public function addGauge(MetricDefinition $definition, array $labels, float $delta): void
    {
        $this->writeMeta($definition);

        $this->run(
            'INSERT INTO telemetry_metric_series (type, name, series, value) VALUES (?, ?, ?, ?)
             ON CONFLICT(type, name, series) DO UPDATE SET value = value + excluded.value',
            [MetricType::Gauge->value, $definition->name, Labels::encode($labels), $delta],
        );
    }

    public function recordHistogram(MetricDefinition $definition, array $labels, float $value, ?Exemplar $exemplar = null): void
    {
        $bounds = $definition->buckets ?? [];
        $slot = $this->bucketIndex($bounds, $value);

        $this->mergeHistogramSlots(
            $definition,
            Labels::encode($labels),
            [$slot => 1],
            $value,
            1,
            $exemplar,
        );
    }

    public function mergeHistogram(MetricDefinition $definition, array $labels, array $bucketCounts, float $sum, int $count, ?Exemplar $exemplar = null): void
    {
        $slots = [];

        foreach ($bucketCounts as $slot => $bucketCount) {
            if ($bucketCount > 0) {
                $slots[$slot] = $bucketCount;
            }
        }

        $this->mergeHistogramSlots($definition, Labels::encode($labels), $slots, $sum, $count, $exemplar);
    }

    /**
     * The one histogram write path: totals and bucket slots move together
     * in a transaction, so a concurrent collect can never read a count
     * that its buckets don't add up to.
     *
     * @param  array<int, int>  $slots  bucket slot => increment
     */
    private function mergeHistogramSlots(MetricDefinition $definition, string $series, array $slots, float $sum, int $count, ?Exemplar $exemplar): void
    {
        $this->writeMeta($definition);

        $pdo = $this->pdo();
        $owns = ! $pdo->inTransaction();

        if ($owns) {
            $pdo->beginTransaction();
        }

        try {
            $this->run(
                'INSERT INTO telemetry_metric_series (type, name, series, sum, count, exemplar) VALUES (?, ?, ?, ?, ?, ?)
                 ON CONFLICT(type, name, series) DO UPDATE SET
                     sum = sum + excluded.sum,
                     count = count + excluded.count,
                     exemplar = COALESCE(excluded.exemplar, telemetry_metric_series.exemplar)',
                [
                    MetricType::Histogram->value,
                    $definition->name,
                    $series,
                    $sum,
                    $count,
                    $exemplar === null ? null : $this->encodeExemplar($exemplar),
                ],
            );

            foreach ($slots as $slot => $increment) {
                $this->run(
                    'INSERT INTO telemetry_metric_buckets (name, series, slot, count) VALUES (?, ?, ?, ?)
                     ON CONFLICT(name, series, slot) DO UPDATE SET count = count + excluded.count',
                    [$definition->name, $series, $slot, $increment],
                );
            }

            if ($owns) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    public function collect(): array
    {
        $families = [];

        foreach ($this->metaRows() as $meta) {
            $definition = $meta['definition'];

            $families[] = $definition->type === MetricType::Histogram
                ? $this->histogramFamily($definition, $meta['since'])
                : $this->scalarFamily($definition, $meta['since']);
        }

        return array_values(array_filter($families));
    }

    /**
     * Drop every value while PRESERVING the meta rows: warm processes
     * memoize their meta write, so deleting the definitions would leave
     * their later writes without a description or buckets until each
     * process recycled. `since` restarts at the wipe, because cumulative
     * series begin again here.
     */
    public function wipe(): void
    {
        $pdo = $this->pdo();
        $owns = ! $pdo->inTransaction();

        if ($owns) {
            $pdo->beginTransaction();
        }

        try {
            $this->run('DELETE FROM telemetry_metric_series', []);
            $this->run('DELETE FROM telemetry_metric_buckets', []);
            $this->run('UPDATE telemetry_metric_meta SET since_unix_nano = ?', [$this->now()]);

            if ($owns) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    public function forgetSeries(MetricDefinition $definition, array $labels): void
    {
        $series = Labels::encode($labels);

        $this->run(
            'DELETE FROM telemetry_metric_series WHERE type = ? AND name = ? AND series = ?',
            [$definition->type->value, $definition->name, $series],
        );

        if ($definition->type === MetricType::Histogram) {
            $this->run(
                'DELETE FROM telemetry_metric_buckets WHERE name = ? AND series = ?',
                [$definition->name, $series],
            );
        }
    }

    private function scalarFamily(MetricDefinition $definition, ?int $since): ?MetricFamily
    {
        $rows = $this->run(
            'SELECT series, value FROM telemetry_metric_series WHERE type = ? AND name = ? ORDER BY series',
            [$definition->type->value, $definition->name],
        )->fetchAll(PDO::FETCH_ASSOC);

        $samples = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['series'] ?? null)) {
                continue;
            }

            $samples[] = new Sample(Labels::decode($row['series']), $this->float($row['value'] ?? null));
        }

        // Meta rows outlive a wipe; a family with no series has no data yet.
        return $samples === [] ? null : new MetricFamily($definition, $samples, $since);
    }

    private function histogramFamily(MetricDefinition $definition, ?int $since): ?MetricFamily
    {
        $rows = $this->run(
            'SELECT series, sum, count, exemplar FROM telemetry_metric_series WHERE type = ? AND name = ? ORDER BY series',
            [MetricType::Histogram->value, $definition->name],
        )->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            return null;
        }

        $bounds = $definition->buckets ?? [];
        $slots = count($bounds) + 1;
        $buckets = $this->bucketsOf($definition->name);
        $samples = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['series'] ?? null)) {
                continue;
            }

            $series = $row['series'];
            $counts = [];

            for ($slot = 0; $slot < $slots; $slot++) {
                $counts[] = $buckets[$series][$slot] ?? 0;
            }

            $samples[] = new HistogramSample(
                labels: Labels::decode($series),
                bounds: $bounds,
                bucketCounts: $counts,
                sum: $this->float($row['sum'] ?? null),
                count: (int) $this->float($row['count'] ?? null),
                exemplar: $this->decodeExemplar($row['exemplar'] ?? null),
            );
        }

        return $samples === [] ? null : new MetricFamily($definition, $samples, $since);
    }

    /**
     * Every bucket slot of one histogram in a single query — per-series
     * lookups would turn one scrape into a query per labelset.
     *
     * @return array<string, array<int, int>>
     */
    private function bucketsOf(string $name): array
    {
        $rows = $this->run(
            'SELECT series, slot, count FROM telemetry_metric_buckets WHERE name = ?',
            [$name],
        )->fetchAll(PDO::FETCH_ASSOC);

        $buckets = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['series'] ?? null)) {
                continue;
            }

            $buckets[$row['series']][(int) $this->float($row['slot'] ?? null)] = (int) $this->float($row['count'] ?? null);
        }

        return $buckets;
    }

    /**
     * @return list<array{definition: MetricDefinition, since: int|null}>
     */
    private function metaRows(): array
    {
        $rows = $this->run(
            'SELECT type, name, description, unit, buckets, since_unix_nano FROM telemetry_metric_meta ORDER BY type, name',
            [],
        )->fetchAll(PDO::FETCH_ASSOC);

        $metas = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['name'] ?? null) || ! is_string($row['type'] ?? null)) {
                continue;
            }

            $type = MetricType::tryFrom($row['type']);

            if ($type === null) {
                continue;
            }

            $buckets = null;

            if (is_string($row['buckets'] ?? null) && $row['buckets'] !== '') {
                $decoded = json_decode($row['buckets'], true);

                if (is_array($decoded)) {
                    $buckets = [];

                    // JSON drops the zero fraction on round floats — a bound
                    // that came back as an int would change the `le=` label.
                    foreach ($decoded as $bound) {
                        if (is_numeric($bound)) {
                            $buckets[] = (float) $bound;
                        }
                    }
                }
            }

            $metas[] = [
                'definition' => new MetricDefinition(
                    name: $row['name'],
                    type: $type,
                    description: is_string($row['description'] ?? null) ? $row['description'] : '',
                    unit: is_string($row['unit'] ?? null) ? $row['unit'] : '',
                    buckets: $buckets,
                ),
                'since' => isset($row['since_unix_nano']) ? (int) $this->float($row['since_unix_nano']) : null,
            ];
        }

        return $metas;
    }

    /**
     * Definition upsert, memoized per process so the steady-state hot path
     * is one statement per observation. Description, unit and buckets are
     * overwritten so a deploy propagates; `since` is left alone so it keeps
     * the first-ever write.
     */
    private function writeMeta(MetricDefinition $definition): void
    {
        $memo = $definition->type->value.':'.$definition->name;

        if (isset($this->meta[$memo])) {
            return;
        }

        $this->meta[$memo] = true;

        $this->run(
            'INSERT INTO telemetry_metric_meta (type, name, description, unit, buckets, since_unix_nano) VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT(type, name) DO UPDATE SET
                 description = excluded.description,
                 unit = excluded.unit,
                 buckets = excluded.buckets',
            [
                $definition->type->value,
                $definition->name,
                $definition->description,
                $definition->unit,
                $definition->buckets === null ? null : json_encode($definition->buckets, JSON_THROW_ON_ERROR),
                $this->now(),
            ],
        );
    }

    /**
     * @param  list<string|int|float|null>  $bindings
     */
    private function run(string $sql, array $bindings): PDOStatement
    {
        $statement = $this->statements[$sql] ??= $this->pdo()->prepare($sql);
        $statement->execute($bindings);

        return $statement;
    }

    private function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $pdo = SqliteConnection::open($this->path, $this->busyTimeoutMs);

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS telemetry_metric_meta (
                type TEXT NOT NULL,
                name TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT \'\',
                unit TEXT NOT NULL DEFAULT \'\',
                buckets TEXT,
                since_unix_nano INTEGER NOT NULL,
                PRIMARY KEY (type, name)
            ) WITHOUT ROWID'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS telemetry_metric_series (
                type TEXT NOT NULL,
                name TEXT NOT NULL,
                series TEXT NOT NULL,
                value REAL NOT NULL DEFAULT 0,
                sum REAL NOT NULL DEFAULT 0,
                count INTEGER NOT NULL DEFAULT 0,
                exemplar TEXT,
                PRIMARY KEY (type, name, series)
            ) WITHOUT ROWID'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS telemetry_metric_buckets (
                name TEXT NOT NULL,
                series TEXT NOT NULL,
                slot INTEGER NOT NULL,
                count INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (name, series, slot)
            ) WITHOUT ROWID'
        );

        return $this->pdo = $pdo;
    }

    private function encodeExemplar(Exemplar $exemplar): string
    {
        return json_encode([
            't' => $exemplar->traceId,
            'v' => $exemplar->value,
            'n' => $exemplar->timeUnixNano,
        ], JSON_THROW_ON_ERROR);
    }

    private function decodeExemplar(mixed $raw): ?Exemplar
    {
        if (! is_string($raw) || $raw === '') {
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

    private function float(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function now(): int
    {
        return (int) (microtime(true) * 1e9);
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
