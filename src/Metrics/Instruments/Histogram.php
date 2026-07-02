<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Metrics\Instruments;

use Cbox\Telemetry\Contracts\MetricStore;
use Cbox\Telemetry\Metrics\MetricDefinition;
use Cbox\Telemetry\Support\FailSafe;
use Closure;

/**
 * A histogram of observed values (durations, sizes, …).
 *
 *     Telemetry::histogram('checkout.duration', unit: 'ms')->record($ms);
 *
 *     $result = Telemetry::histogram('import.duration', unit: 'ms')
 *         ->time(fn () => $importer->run());
 */
final readonly class Histogram
{
    public function __construct(
        private MetricDefinition $definition,
        private MetricStore $store,
    ) {}

    /**
     * @param  array<string, scalar|null>  $labels
     */
    public function record(float $value, array $labels = []): void
    {
        FailSafe::guard(fn () => $this->store->recordHistogram(
            $this->definition,
            $this->stringify($labels),
            $value,
        ));
    }

    /**
     * Measure the closure's wall time in milliseconds and record it.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @param  array<string, scalar|null>  $labels
     * @return T
     */
    public function time(Closure $callback, array $labels = []): mixed
    {
        $start = hrtime(true);

        try {
            return $callback();
        } finally {
            $this->record((hrtime(true) - $start) / 1_000_000, $labels);
        }
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
