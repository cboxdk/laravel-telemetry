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
 *     Telemetry::histogram('checkout.duration', unit: 's')->record($seconds);
 *
 *     $result = Telemetry::histogram('import.duration', unit: 's')
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
        // NAN and INF are not observations. Redis refuses to increment
        // a sum by either, which fails the write HALFWAY through — the
        // bucket has already been counted — and leaves a series whose
        // buckets outnumber its count. A division by a zero rate, an
        // unset timer subtracted from a set one, and there it is; the
        // arithmetic that produced it belongs to the application and
        // the corrupt series would belong to us.
        if (! is_finite($value)) {
            return;
        }

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
            // In the instrument's own unit, not a fixed one: a histogram
            // declared in seconds and filled by a helper that always wrote
            // milliseconds would be wrong by a factor of a thousand, and
            // nothing about the reading would look odd.
            $nanoseconds = hrtime(true) - $start;

            $this->record(match ($this->definition->unit) {
                's' => $nanoseconds / 1_000_000_000,
                'ms' => $nanoseconds / 1_000_000,
                'us' => $nanoseconds / 1_000,
                'ns' => (float) $nanoseconds,
                // An unknown unit cannot be a duration; seconds is the
                // convention, so time in seconds.
                default => $nanoseconds / 1_000_000_000,
            }, $labels);
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
