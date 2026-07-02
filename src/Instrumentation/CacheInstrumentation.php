<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\SpanKind;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Cache\Events\KeyForgetFailed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\RetrievingManyKeys;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Cache\Events\WritingManyKeys;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Cache instrumentation, two independent modes:
 *
 * - Counters (`instrument.cache`): cache.operations{operation, store} —
 *   effectiveness aggregates, deliberately WITHOUT key labels
 *   (unbounded metric cardinality).
 * - Timeline spans (`instrument.cache_spans`): a backdated span per
 *   cache operation — cache.hit / cache.miss / cache.write /
 *   cache.forget — WITH the key, store and real duration (measured
 *   between Laravel's before/after cache events). Keys are safe on
 *   spans: they are per-occurrence, not aggregated. This is the
 *   Nightwatch-style timeline with hundreds of cache events per request.
 *
 * Spans are only recorded inside a sampled active trace; the tracer's
 * buffer cap bounds pathological requests.
 */
final class CacheInstrumentation
{
    private const MAX_PENDING = 1000;

    /** @var array<string, list<float>> start times keyed by store:key */
    private array $pending = [];

    private bool $counters = false;

    private bool $spans = false;

    /** @var list<string> */
    private array $ignoreStores = [];

    public function __construct(private readonly Container $container) {}

    private function telemetry(): TelemetryManager
    {
        return $this->container->make(TelemetryManager::class);
    }

    /**
     * @param  list<string>  $ignoreStores
     */
    public function register(Dispatcher $events, bool $counters = true, bool $spans = false, array $ignoreStores = []): void
    {
        $this->counters = $counters;
        $this->spans = $spans;
        $this->ignoreStores = $ignoreStores;

        $events->listen(CacheHit::class, fn (CacheHit $event) => $this->complete('hit', $event->storeName, $event->key));
        $events->listen(CacheMissed::class, fn (CacheMissed $event) => $this->complete('miss', $event->storeName, $event->key));
        $events->listen(KeyWritten::class, fn (KeyWritten $event) => $this->complete('write', $event->storeName, $event->key));
        $events->listen(KeyForgotten::class, fn (KeyForgotten $event) => $this->complete('forget', $event->storeName, $event->key));
        $events->listen(KeyWriteFailed::class, fn (KeyWriteFailed $event) => $this->complete('write_failed', $event->storeName, $event->key));
        $events->listen(KeyForgetFailed::class, fn (KeyForgetFailed $event) => $this->complete('forget_failed', $event->storeName, $event->key));

        if ($spans) {
            $events->listen(RetrievingKey::class, fn (RetrievingKey $event) => $this->begin($event->storeName, $event->key));
            $events->listen(RetrievingManyKeys::class, function (RetrievingManyKeys $event) {
                foreach ($event->keys as $key) {
                    $this->begin($event->storeName, (string) $key);
                }
            });
            $events->listen(WritingKey::class, fn (WritingKey $event) => $this->begin($event->storeName, $event->key));
            $events->listen(WritingManyKeys::class, function (WritingManyKeys $event) {
                foreach ($event->keys as $key) {
                    $this->begin($event->storeName, (string) $key);
                }
            });
            $events->listen(ForgettingKey::class, fn (ForgettingKey $event) => $this->begin($event->storeName, $event->key));
        }
    }

    private function begin(?string $store, string $key): void
    {
        if (in_array($store ?? 'default', $this->ignoreStores, true)) {
            return;
        }

        if (count($this->pending) >= self::MAX_PENDING) {
            $this->pending = [];
        }

        $this->pending[($store ?? 'default').':'.$key][] = microtime(true);
    }

    private function complete(string $operation, ?string $store, string $key): void
    {
        FailSafe::guard(function () use ($operation, $store, $key) {
            if (in_array($store ?? 'default', $this->ignoreStores, true)) {
                return;
            }

            $telemetry = $this->telemetry();

            // App-defined key classification (classifyCacheKeysUsing):
            // group => bounded label/attribute, null => drop the
            // operation. How a Stache-style subsystem with thousands of
            // raw keys stays readable.
            $group = null;

            if ($telemetry->hasCacheKeyClassifier()) {
                $group = $telemetry->classifyCacheKey($store ?? 'default', $key);

                if ($group === null) {
                    return;
                }
            }

            if ($this->counters) {
                $telemetry->counter('cache.operations', 'Cache operations by outcome')
                    ->inc(1, array_filter([
                        'operation' => $operation,
                        'store' => $store ?? 'default',
                        'key_group' => $group,
                    ], static fn ($value) => $value !== null));
            }

            if (! $this->spans) {
                return;
            }

            $current = $telemetry->currentSpan();

            if ($current === null || ! $current->sampled) {
                return;
            }

            $pendingKey = ($store ?? 'default').':'.$key;
            $started = null;

            if (isset($this->pending[$pendingKey]) && $this->pending[$pendingKey] !== []) {
                $started = array_pop($this->pending[$pendingKey]);

                if ($this->pending[$pendingKey] === []) {
                    unset($this->pending[$pendingKey]);
                }
            }

            $durationMs = $started !== null ? max(0.0, (microtime(true) - $started) * 1000) : 0.0;

            $telemetry->tracer()->recordSpan(
                "cache.{$operation}",
                $durationMs,
                array_filter([
                    'cache.key' => $key,
                    'cache.store' => $store ?? 'default',
                    'cache.operation' => $operation,
                    'cache.key.group' => $group,
                ], static fn ($value) => $value !== null),
                SpanKind::Client,
                detail: true,
            );

            $telemetry->tracer()->bumpStat('cache.event.count', 1);
            $telemetry->tracer()->bumpStat('cache.event.time_ms', $durationMs);
        });
    }
}
