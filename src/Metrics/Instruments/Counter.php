<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Metrics\Instruments;

use Cbox\Telemetry\Contracts\MetricStore;
use Cbox\Telemetry\Metrics\MetricDefinition;
use Cbox\Telemetry\Support\FailSafe;

/**
 * A monotonically increasing counter.
 *
 * Writes go straight to the shared metric store — the value survives the
 * request and aggregates across processes and nodes.
 *
 *     Telemetry::counter('orders.created')->inc();
 *     Telemetry::counter('mail.sent')->inc(3, ['transport' => 'ses']);
 */
final readonly class Counter
{
    public function __construct(
        private MetricDefinition $definition,
        private MetricStore $store,
    ) {}

    /**
     * @param  array<string, scalar|null>  $labels
     */
    public function inc(float $by = 1.0, array $labels = []): void
    {
        if ($by < 0) {
            return; // Counters are monotonic; ignore invalid decrements.
        }

        FailSafe::guard(fn () => $this->store->incrementCounter(
            $this->definition,
            $this->stringify($labels),
            $by,
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
