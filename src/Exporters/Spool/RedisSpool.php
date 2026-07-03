<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Exporters\Spool;

use Illuminate\Contracts\Redis\Factory;

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
        $encoded = json_encode($entry, JSON_INVALID_UTF8_SUBSTITUTE);

        if (! is_string($encoded)) {
            return; // unencodable payload — drop rather than poison the list
        }

        $connection = $this->redis->connection($this->connection);

        $connection->rpush($this->key, $encoded);

        // Keep the newest $maxItems — backpressure by dropping the oldest.
        $connection->ltrim($this->key, -$this->maxItems, -1);
    }

    public function pop(int $count): array
    {
        $connection = $this->redis->connection($this->connection);
        $entries = [];

        for ($i = 0; $i < $count; $i++) {
            $raw = $connection->lpop($this->key);

            if (! is_string($raw)) {
                break;
            }

            $entry = json_decode($raw, true);

            if (is_array($entry) && is_string($entry['signal'] ?? null) && is_array($entry['payload'] ?? null)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    public function requeue(array $entries): void
    {
        $connection = $this->redis->connection($this->connection);

        // lpush reversed keeps the original order at the front.
        foreach (array_reverse($entries) as $entry) {
            $encoded = json_encode($entry, JSON_INVALID_UTF8_SUBSTITUTE);

            if (is_string($encoded)) {
                $connection->lpush($this->key, $encoded);
            }
        }
    }

    public function size(): int
    {
        return (int) $this->redis->connection($this->connection)->llen($this->key);
    }
}
