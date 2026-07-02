<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Cache effectiveness counters: cache.operations{operation, store}.
 *
 * Deliberately no key label (unbounded cardinality) and no spans (a hot
 * page can hit the cache hundreds of times). Hit ratio in PromQL:
 *
 *     sum(rate(cache_operations_total{operation="hit"}[5m]))
 *       / sum(rate(cache_operations_total{operation=~"hit|miss"}[5m]))
 */
final class CacheInstrumentation
{
    public function __construct(private readonly Container $container) {}

    private function telemetry(): TelemetryManager
    {
        return $this->container->make(TelemetryManager::class);
    }

    public function register(Dispatcher $events): void
    {
        $events->listen(CacheHit::class, fn (CacheHit $event) => $this->count('hit', $event->storeName));
        $events->listen(CacheMissed::class, fn (CacheMissed $event) => $this->count('miss', $event->storeName));
        $events->listen(KeyWritten::class, fn (KeyWritten $event) => $this->count('write', $event->storeName));
        $events->listen(KeyForgotten::class, fn (KeyForgotten $event) => $this->count('forget', $event->storeName));
    }

    private function count(string $operation, ?string $store): void
    {
        FailSafe::guard(fn () => $this->telemetry()
            ->counter('cache.operations', 'Cache operations by outcome')
            ->inc(1, ['operation' => $operation, 'store' => $store ?? 'default']));
    }
}
