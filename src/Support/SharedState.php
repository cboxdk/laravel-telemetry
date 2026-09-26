<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

/**
 * Deadlines that outlive a single PHP request.
 *
 * Two mechanisms in this package suppress work for a while after a
 * failure: the OTLP circuit breaker and the report throttle. Both were
 * plain static properties, which is right in a long-lived worker
 * (Octane, a queue worker, the scheduler) and wrong under PHP-FPM,
 * where every request begins with a fresh engine state. Under FPM a
 * "30 second" circuit breaker was a no-op: each request rediscovered
 * the dead collector and paid the connect timeout again. At a few
 * thousand requests a minute that is the failure this package exists
 * to avoid — telemetry becoming the outage.
 *
 * APCu, when present, is shared by every worker in the pool and costs
 * a shared-memory read. Without it we fall back to per-process state:
 * the previous behaviour, which is exactly right for the long-lived
 * processes where APCu is usually disabled (`apc.enable_cli=0` is the
 * default). `telemetry:doctor` reports which of the two is in force.
 *
 * No disk and no network, deliberately: a deadline is a hint about a
 * backend that is already failing, and paying I/O to remember it would
 * defeat the point of having it.
 */
final class SharedState
{
    /**
     * Keys this process has written, so flush() can clear them. Capped
     * because nothing may grow without bound in a worker that lives for
     * days; the values themselves expire on their own. The ceiling is
     * well above the number of metric families a real application
     * declares, because reaching it costs those families their
     * bookkeeping memo and the round trips it was saving.
     */
    private const MAX_KEYS = 2048;

    private static ?SharedMemory $memory = null;

    /** @var array<string, true> */
    private static array $keys = [];

    public static function memory(): SharedMemory
    {
        return self::$memory ??= ApcuMemory::available()
            ? new ApcuMemory
            : new ProcessMemory;
    }

    /**
     * Swap the backing memory. Pass null to re-detect — which is also
     * how a test returns to the default.
     */
    public static function use(?SharedMemory $memory): void
    {
        self::flush();
        self::$memory = $memory;
    }

    /**
     * The deadline stored for a key, or 0 when there is none. Callers
     * compare it against time() themselves — an expired deadline and an
     * absent one mean the same thing.
     */
    public static function deadline(string $key): int
    {
        return self::memory()->get($key) ?? 0;
    }

    /**
     * Remember a deadline. One already in the past is dropped rather
     * than stored, so it cannot occupy a slot.
     */
    public static function remember(string $key, int $until): void
    {
        $ttl = $until - time();

        if ($ttl <= 0) {
            self::forget($key);

            return;
        }

        if (count(self::$keys) >= self::MAX_KEYS) {
            self::flush();
        }

        self::$keys[$key] = true;
        self::memory()->put($key, $until, $ttl);
    }

    public static function forget(string $key): void
    {
        unset(self::$keys[$key]);
        self::memory()->forget($key);
    }

    /**
     * Drop every deadline this process has written. For tests, and for
     * an operator who has just fixed a backend and does not want to
     * wait out a cooldown.
     */
    public static function flush(): void
    {
        foreach (array_keys(self::$keys) as $key) {
            self::memory()->forget($key);
        }

        self::$keys = [];
    }

    /**
     * Whether deadlines are shared across the workers of this pool.
     * When false they are per-process, and under FPM that means per
     * request.
     */
    public static function isShared(): bool
    {
        return self::memory()->isShared();
    }
}
