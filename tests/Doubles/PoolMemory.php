<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Tests\Doubles;

use Cbox\Telemetry\Support\SharedMemory;

/**
 * An APCu segment, standing still.
 *
 * APCu is not available in CI (nor in most CLI SAPIs), so the shared
 * path would otherwise be the one path that never runs in a test — the
 * path that only matters in production, under FPM, during an outage.
 * This double behaves like the pool's shared memory: it survives the
 * statics of a request being thrown away, which is the whole property
 * under test.
 */
final class PoolMemory implements SharedMemory
{
    /** @var array<string, int> */
    private array $deadlines = [];

    public int $writes = 0;

    public function get(string $key): ?int
    {
        $until = $this->deadlines[$key] ?? null;

        if ($until !== null && $until <= time()) {
            unset($this->deadlines[$key]);

            return null;
        }

        return $until;
    }

    public function put(string $key, int $until, int $ttlSeconds): void
    {
        $this->deadlines[$key] = $until;
        $this->writes++;
    }

    public function forget(string $key): void
    {
        unset($this->deadlines[$key]);
    }

    public function isShared(): bool
    {
        return true;
    }

    /** @return array<string, int> */
    public function all(): array
    {
        return $this->deadlines;
    }

    /**
     * Move every deadline into the past — time passing, rather than
     * the entries being cleared.
     *
     * The distinction matters: clearing would also "pass" against a
     * memo that is merely a boolean, which is the thing several of
     * these windows exist to avoid.
     */
    public function expireAll(): void
    {
        foreach ($this->deadlines as $key => $until) {
            $this->deadlines[$key] = time() - 3600;
        }
    }
}
