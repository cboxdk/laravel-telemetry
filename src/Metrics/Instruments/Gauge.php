<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Metrics\Instruments;

use Cbox\Telemetry\Contracts\MetricStore;
use Cbox\Telemetry\Metrics\MetricDefinition;
use Cbox\Telemetry\Support\FailSafe;

/**
 * A push gauge — a value set at event time, stored in the shared store.
 *
 *     Telemetry::gauge('cache.warmed_keys')->set(1240);
 *
 * For values that are cheap to read on demand, prefer an observable gauge
 * (pass a callback to Telemetry::gauge()) — it is evaluated at scrape time
 * and needs no storage at all.
 */
final readonly class Gauge
{
    public function __construct(
        private MetricDefinition $definition,
        private MetricStore $store,
    ) {}

    /**
     * @param  array<string, scalar|null>  $labels
     */
    public function set(float $value, array $labels = []): void
    {
        FailSafe::guard(fn () => $this->store->setGauge(
            $this->definition,
            $this->stringify($labels),
            $value,
        ));
    }

    public function definition(): MetricDefinition
    {
        return $this->definition;
    }

    /**
     * @param  array<string, scalar|null>  $labels
     * @return array<string, string>
     */
    private function stringify(array $labels): array
    {
        return array_map(static fn ($value): string => $value === null ? '' : (string) $value, $labels);
    }
}
