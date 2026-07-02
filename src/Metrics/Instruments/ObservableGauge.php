<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Metrics\Instruments;

use Cbox\Telemetry\Metrics\MetricDefinition;
use Cbox\Telemetry\Metrics\MetricFamily;
use Cbox\Telemetry\Metrics\Sample;
use Closure;

/**
 * A pull gauge — a callback evaluated at scrape/flush time.
 *
 * Nothing is ever stored; the callback queries the source of truth on
 * demand. A single callback may return one value or many series:
 *
 *     Telemetry::gauge('queue.depth', fn () => Queue::size());
 *
 *     Telemetry::gauge('queue.depth', fn () => [
 *         [12, ['queue' => 'default']],
 *         [3,  ['queue' => 'mail']],
 *     ]);
 */
final readonly class ObservableGauge
{
    public function __construct(
        private MetricDefinition $definition,
        private Closure $callback,
    ) {}

    /**
     * Evaluate the callback into an exportable family.
     */
    public function observe(): MetricFamily
    {
        $result = ($this->callback)();

        return new MetricFamily($this->definition, $this->normalize($result));
    }

    public function definition(): MetricDefinition
    {
        return $this->definition;
    }

    /**
     * @return list<Sample>
     */
    private function normalize(mixed $result): array
    {
        if (is_int($result) || is_float($result)) {
            return [new Sample([], (float) $result)];
        }

        if (! is_array($result)) {
            return [];
        }

        $samples = [];

        foreach ($result as $entry) {
            if (is_int($entry) || is_float($entry)) {
                $samples[] = new Sample([], (float) $entry);

                continue;
            }

            if (is_array($entry) && array_is_list($entry) && count($entry) >= 1 && is_numeric($entry[0])) {
                /** @var array<string, scalar|null> $labels */
                $labels = is_array($entry[1] ?? null) ? $entry[1] : [];

                $samples[] = new Sample(
                    array_map(static fn ($value): string => $value === null ? '' : (string) $value, $labels),
                    (float) $entry[0],
                );
            }
        }

        return $samples;
    }
}
