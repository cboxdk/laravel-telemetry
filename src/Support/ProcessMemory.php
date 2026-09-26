<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

/**
 * Deadlines held in this process only.
 *
 * Correct for the processes that actually live a long time — an Octane
 * worker, a queue worker, the scheduler — and the fallback when no
 * shared memory is available. Under PHP-FPM it degrades to per-request,
 * which `telemetry:doctor` reports rather than hides.
 */
final class ProcessMemory implements SharedMemory
{
    /** @var array<string, int> key → unix time the deadline expires */
    private array $deadlines = [];

    public function get(string $key): ?int
    {
        $until = $this->deadlines[$key] ?? null;

        if ($until === null) {
            return null;
        }

        if ($until <= time()) {
            unset($this->deadlines[$key]);

            return null;
        }

        return $until;
    }

    public function put(string $key, int $until, int $ttlSeconds): void
    {
        $this->deadlines[$key] = $until;
    }

    public function forget(string $key): void
    {
        unset($this->deadlines[$key]);
    }

    public function isShared(): bool
    {
        return false;
    }
}
