<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

/**
 * Deadlines in APCu, shared by every worker in an FPM pool.
 *
 * A shared-memory read with no lock and no syscall, present in
 * effectively every production PHP image. This is what makes a "30
 * second" circuit breaker mean thirty seconds under FPM rather than
 * one request.
 */
final class ApcuMemory implements SharedMemory
{
    private const PREFIX = 'cbox:telemetry:';

    public static function available(): bool
    {
        return function_exists('apcu_enabled')
            && function_exists('apcu_store')
            && apcu_enabled();
    }

    public function get(string $key): ?int
    {
        /** @var mixed $value */
        $value = apcu_fetch(self::PREFIX.$key, $found);

        return $found === true && is_int($value) ? $value : null;
    }

    public function put(string $key, int $until, int $ttlSeconds): void
    {
        apcu_store(self::PREFIX.$key, $until, $ttlSeconds);
    }

    public function forget(string $key): void
    {
        apcu_delete(self::PREFIX.$key);
    }

    public function isShared(): bool
    {
        return true;
    }
}
