<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Metrics\Instruments;

use Cbox\Telemetry\Contracts\MetricStore;
use Cbox\Telemetry\Metrics\Exemplar;
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
 *
 * When a sampled trace is active, every observation carries it as an
 * exemplar — automatically, no call-site changes: click a slow bucket in
 * Grafana, land on an actual trace that was in it.
 */
final readonly class Histogram
{
    /**
     * @param  (Closure(): ?string)|null  $exemplarTraceId  Resolves the
     *                                                      current sampled
     *                                                      trace id, or
     *                                                      null outside
     *                                                      one.
     */
    public function __construct(
        private MetricDefinition $definition,
        private MetricStore $store,
        private ?Closure $exemplarTraceId = null,
    ) {}

    /**
     * @param  array<string, scalar|null>  $labels
     */
    public function record(float $value, array $labels = []): void
    {
        FailSafe::guard(function () use ($value, $labels) {
            $traceId = $this->exemplarTraceId !== null ? ($this->exemplarTraceId)() : null;

            $this->store->recordHistogram(
                $this->definition,
                $this->stringify($labels),
                $value,
                $traceId !== null ? new Exemplar($traceId, $value, (int) (microtime(true) * 1e9)) : null,
            );
        });
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
