<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Exporters\Spool;

use Illuminate\Contracts\Redis\Factory;
use Throwable;

/**
 * Redis-list spool. One key, list ops only (cluster-safe, invariant #2
 * holds: no KEYS/SCAN). Capped with drop-oldest semantics — a dead
 * daemon costs the oldest telemetry, never app memory or an unbounded
 * keyspace.
 */
final class RedisSpool implements Spool
{
    public function __construct(
        private readonly Factory $redis,
        private readonly string $connection = 'default',
        private readonly string $key = 'telemetry:spool',
        private readonly int $maxItems = 20000,
    ) {}

    /** Whether this server understood `LPOP key count` (Redis 6.2+). */
    private bool $batchPop = true;

    public function push(array $entry): void
    {
        $this->pushMany([$entry]);
    }

    public function pushMany(array $entries): void
    {
        $encoded = [];

        foreach ($entries as $entry) {
            $json = json_encode($entry, JSON_INVALID_UTF8_SUBSTITUTE);

            if (is_string($json)) {
                $encoded[] = $json; // an unencodable payload is dropped, not poisoned into the list
            }
        }

        if ($encoded === []) {
            return;
        }

        $connection = $this->redis->connection($this->connection);

        // One variadic RPUSH for the whole batch: a request with spans
        // and events pays two commands in total, not four.
        $connection->command('rpush', [$this->key, ...$encoded]);

        // Keep the newest $maxItems — backpressure by dropping the oldest.
        $connection->command('ltrim', [$this->key, -$this->maxItems, -1]);
    }

    /**
     * One LPOP for the whole batch, not one per entry.
     *
     * A batch of two hundred was two hundred synchronous round trips —
     * on a remote Redis that is most of what the drain costs, and it
     * was paid again for the two hundred that came back empty at the
     * tail of a drain. `LPOP key count` is one command and atomic, so
     * two daemons cannot read the same entries.
     *
     * It needs Redis 6.2. Older servers answer with an error, which is
     * caught once and remembered — the per-entry loop still works, and
     * a spool that silently stopped draining on Redis 6.0 would be a
     * worse bug than the round trips.
     */
    public function pop(int $count): array
    {
        if ($count < 1) {
            return [];
        }

        $connection = $this->redis->connection($this->connection);

        $tried = false;

        if ($this->batchPop) {
            try {
                $tried = true;

                /** @var mixed $raw */
                $raw = $connection->command('lpop', [$this->key, $count]);

                if (is_array($raw)) {
                    return $this->decodeAll($raw);
                }
            } catch (Throwable) {
                $this->batchPop = false;
                $tried = false;
            }
        }

        // A non-array answer is ambiguous: phpredis returns false both
        // for an empty list and for a command the server did not
        // understand. One single-key LPOP settles it — and treating
        // "did not understand" as "empty" would have made the spool
        // look permanently drained on Redis before 6.2, which is
        // silent, total data loss.
        $entries = [];

        for ($i = 0; $i < $count; $i++) {
            $raw = $connection->command('lpop', [$this->key]);

            if (! is_string($raw)) {
                break;
            }

            $entry = SpoolEntry::decode($raw);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        if ($tried && $entries !== []) {
            // The list was not empty, so the count form was refused.
            $this->batchPop = false;
        }

        return $entries;
    }

    /**
     * @param  array<mixed>  $raw
     * @return list<array{signal: string, payload: array<string, mixed>}>
     */
    private function decodeAll(array $raw): array
    {
        $entries = [];

        foreach ($raw as $encoded) {
            $entry = is_string($encoded) ? SpoolEntry::decode($encoded) : null;

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    public function requeue(array $entries): void
    {
        $encoded = [];

        // Reversed, so lpush leaves the original order at the front.
        foreach (array_reverse($entries) as $entry) {
            $json = json_encode($entry, JSON_INVALID_UTF8_SUBSTITUTE);

            if (is_string($json)) {
                $encoded[] = $json;
            }
        }

        if ($encoded === []) {
            return;
        }

        // One variadic LPUSH: requeueing happens when the collector is
        // already unreachable, and two hundred round trips is the worst
        // moment to spend them.
        $this->redis->connection($this->connection)->command('lpush', [$this->key, ...$encoded]);
    }

    public function size(): int
    {
        return (int) $this->redis->connection($this->connection)->llen($this->key);
    }
}
