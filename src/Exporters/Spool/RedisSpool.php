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

        try {
            /** @var mixed $raw */
            $raw = $connection->command('lpop', [$this->key, $count]);

            if (is_array($raw)) {
                return $this->decodeAll($raw);
            }
        } catch (Throwable $e) {
            // Two very different failures arrive the same way.
            //
            // A server that does not know the count form REJECTS the
            // command — nothing was popped, and the per-entry loop
            // below is the whole point. Older clients raise that as an
            // argument-count error rather than returning false.
            //
            // Anything else may have RUN and only lost its reply, and
            // those entries are already off the list; popping again
            // would skip past them for good. That tick ships nothing
            // and the next starts clean.
            if (! self::isArgumentRejection($e)) {
                return [];
            }
        }

        // A non-array answer is ambiguous: phpredis returns false both
        // for an empty list and for a command the server did not
        // understand. Treating it as "empty" would make the spool look
        // permanently drained on Redis before 6.2 — silent, total data
        // loss with the daemon reporting success throughout — so the
        // per-entry form settles it.
        //
        // No latch on the outcome. Inferring "this server is old" from
        // one ambiguous answer is wrong whenever a producer pushes
        // between the two calls, and being wrong costs every later
        // batch its round trips. One wasted command per drain on an
        // old server is the cheaper mistake.
        $entries = [];

        for ($i = 0; $i < $count; $i++) {
            $encoded = $connection->command('lpop', [$this->key]);

            if (! is_string($encoded)) {
                break;
            }

            $entry = SpoolEntry::decode($encoded);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Whether the server refused the command before running it.
     *
     * `LPOP key count` needs Redis 6.2. Older ones answer "wrong
     * number of arguments", which is a rejection: nothing was popped,
     * and retrying per entry is safe.
     */
    private static function isArgumentRejection(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'wrong number of arguments')
            || str_contains($message, 'unknown command')
            || str_contains($message, 'syntax error');
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
