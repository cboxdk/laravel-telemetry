<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\Cast;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Pennant\Events\FeatureRetrieved;
use Laravel\Pennant\Events\UnknownFeatureResolved;

/**
 * Feature-flag check counters via `laravel/pennant`'s own events — no
 * dependency on Pennant beyond the event classes, guarded by
 * `class_exists()` at registration.
 *
 * `FeatureRetrieved` fires on every `Feature::active()`/`value()` check,
 * cache hit or fresh resolve, so `feature.checks` is a true call-count.
 * The scope (typically a user/tenant model) is NEVER used as a label —
 * only the feature name and a bounded result.
 */
final class PennantInstrumentation
{
    public function __construct(private readonly Container $container) {}

    private function telemetry(): TelemetryManager
    {
        return $this->container->make(TelemetryManager::class);
    }

    public function register(Dispatcher $events): void
    {
        $events->listen(FeatureRetrieved::class, $this->retrieved(...));
        $events->listen(UnknownFeatureResolved::class, $this->unknown(...));
    }

    private function retrieved(FeatureRetrieved $event): void
    {
        FailSafe::guard(function () use ($event) {
            $this->telemetry()
                ->counter('feature.checks', 'Feature flag checks by result')
                ->inc(1, [
                    'feature' => Cast::string($event->feature),
                    'result' => $this->result($event->value),
                ]);
        });
    }

    private function unknown(UnknownFeatureResolved $event): void
    {
        FailSafe::guard(function () use ($event) {
            $this->telemetry()
                ->counter('feature.unknown', 'Checks against a feature with no registered definition — likely a typo or a stale flag')
                ->inc(1, ['feature' => Cast::string($event->feature)]);
        });
    }

    /**
     * Bounded result label: booleans (the common case) become
     * active/inactive; scalar variant values (A/B test arms, rollout
     * tiers) are passed through as-is — keep those variant sets small,
     * the same caller discipline as any other custom label.
     */
    private function result(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'active' : 'inactive';
        }

        return Cast::string($value, 'unknown');
    }
}
