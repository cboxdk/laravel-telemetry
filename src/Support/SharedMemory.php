<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

/**
 * Where a deadline is kept.
 *
 * Deliberately tiny: the only thing this package needs to remember
 * between requests is "do not try this again until T", for the OTLP
 * circuit breaker and the report throttle. Anything richer would be a
 * cache, and a telemetry package has no business holding one.
 */
interface SharedMemory
{
    /** The deadline stored for a key, or null when there is none. */
    public function get(string $key): ?int;

    public function put(string $key, int $until, int $ttlSeconds): void;

    public function forget(string $key): void;

    /**
     * Whether this memory is shared by every worker in the pool. When
     * false, deadlines are per-process — which under PHP-FPM means per
     * request.
     */
    public function isShared(): bool;
}
