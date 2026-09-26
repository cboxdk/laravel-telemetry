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

    /**
     * Seconds added to every entry's APCu lifetime.
     *
     * The deadline we store IS the authority — callers compare it with
     * time() — so the lifetime only has to outlive it. The padding is
     * for `apc.use_request_time=1`, where APCu measures expiry from the
     * START of the request: a request that runs for thirty-one seconds
     * and then stores a thirty-second cooldown would otherwise write an
     * entry that is already expired.
     */
    private const LIFETIME_PADDING = 300;

    /**
     * @param  string  $namespace  distinguishes applications that share
     *                             this APCu segment — one pool's dead
     *                             collector must not silence another's
     */
    public function __construct(private readonly string $namespace) {}

    public static function available(): bool
    {
        return function_exists('apcu_enabled')
            && function_exists('apcu_store')
            && apcu_enabled();
    }

    public function get(string $key): ?int
    {
        /** @var mixed $value */
        $value = apcu_fetch($this->key($key), $found);

        return $found === true && is_int($value) ? $value : null;
    }

    public function put(string $key, int $until, int $ttlSeconds): void
    {
        apcu_store($this->key($key), $until, $ttlSeconds + self::LIFETIME_PADDING);
    }

    public function forget(string $key): void
    {
        apcu_delete($this->key($key));
    }

    private function key(string $key): string
    {
        return self::PREFIX.$this->namespace.':'.$key;
    }

    public function isShared(): bool
    {
        return true;
    }
}
