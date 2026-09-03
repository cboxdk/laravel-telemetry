<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Exporters\Spool;

use Cbox\Telemetry\Exceptions\SqliteUnavailable;
use Cbox\Telemetry\Support\SqliteConnection;
use PDO;
use PDOStatement;

/**
 * File-backed spool for devices — NativePHP mobile and desktop, where
 * there is no Redis and the network comes and goes.
 *
 * The difference that matters against RedisSpool: this one survives the
 * app being killed. A phone that spent the afternoon in a tunnel still
 * has its telemetry when it comes back on wifi.
 *
 * Ordering is an explicit signed `seq` rather than a rowid, because
 * requeue has to put a failed batch back at the FRONT — it inserts below
 * the current minimum, which a monotonic rowid could not express.
 * Capped with drop-oldest semantics: a daemon that never runs costs the
 * oldest telemetry, never unbounded disk.
 */
final class SqliteSpool implements Spool
{
    private ?PDO $pdo = null;

    /** @var array<string, PDOStatement> prepared statement cache */
    private array $statements = [];

    public function __construct(
        private readonly string $path,
        private readonly int $maxItems = 20000,
        private readonly int $busyTimeoutMs = 5000,
    ) {
        if (! extension_loaded('pdo_sqlite')) {
            throw new SqliteUnavailable;
        }
    }

    public function push(array $entry): void
    {
        $encoded = json_encode($entry, JSON_INVALID_UTF8_SUBSTITUTE);

        if (! is_string($encoded)) {
            return; // unencodable payload — drop rather than poison the queue
        }

        $this->transaction(function () use ($encoded): void {
            $this->run(
                'INSERT INTO telemetry_spool (seq, entry)
                 VALUES ((SELECT COALESCE(MAX(seq), 0) + 1 FROM telemetry_spool), ?)',
                [$encoded],
            );

            // Backpressure: keep the newest $maxItems. The seq range stays
            // contiguous — pushes append, requeues prepend, and both pop
            // and this trim take from an end — so one indexed range delete
            // is the whole cap.
            $this->run(
                'DELETE FROM telemetry_spool WHERE seq <= (SELECT MAX(seq) FROM telemetry_spool) - ?',
                [$this->maxItems],
            );
        });
    }

    public function pop(int $count): array
    {
        if ($count < 1) {
            return [];
        }

        return $this->transaction(function () use ($count): array {
            $rows = $this->run(
                'SELECT seq, entry FROM telemetry_spool ORDER BY seq LIMIT ?',
                [$count],
            )->fetchAll(PDO::FETCH_ASSOC);

            $entries = [];
            $seqs = [];

            foreach ($rows as $row) {
                if (! is_array($row) || ! is_string($row['entry'] ?? null) || ! is_numeric($row['seq'] ?? null)) {
                    continue;
                }

                $seqs[] = (int) $row['seq'];
                $entry = SpoolEntry::decode($row['entry']);

                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }

            if ($seqs !== []) {
                // Delete exactly what was read, never a range: a requeue
                // running alongside inserts below the minimum, and a range
                // delete would take those unshipped entries with it.
                $this->run(
                    'DELETE FROM telemetry_spool WHERE seq IN ('.implode(',', array_fill(0, count($seqs), '?')).')',
                    $seqs,
                );
            }

            return $entries;
        });
    }

    public function requeue(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $this->transaction(function () use ($entries): void {
            $next = $this->minSeq() - count($entries);

            foreach ($entries as $entry) {
                $encoded = json_encode($entry, JSON_INVALID_UTF8_SUBSTITUTE);

                if (is_string($encoded)) {
                    $this->run('INSERT INTO telemetry_spool (seq, entry) VALUES (?, ?)', [$next, $encoded]);
                }

                $next++;
            }
        });
    }

    public function size(): int
    {
        $count = $this->run('SELECT COUNT(*) AS c FROM telemetry_spool', [])->fetchColumn();

        return is_numeric($count) ? (int) $count : 0;
    }

    private function minSeq(): int
    {
        $min = $this->run('SELECT COALESCE(MIN(seq), 0) AS m FROM telemetry_spool', [])->fetchColumn();

        return is_numeric($min) ? (int) $min : 0;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function transaction(callable $work): mixed
    {
        $pdo = $this->pdo();
        $owns = ! $pdo->inTransaction();

        if ($owns) {
            $pdo->beginTransaction();
        }

        try {
            $result = $work();

            if ($owns) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
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
            'CREATE TABLE IF NOT EXISTS telemetry_spool (
                seq INTEGER PRIMARY KEY,
                entry TEXT NOT NULL
            )'
        );

        return $this->pdo = $pdo;
    }
}
