<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Metrics;

use Cbox\Telemetry\Exceptions\InvalidMetricName;

/**
 * Immutable identity of a metric: name, type, description, unit and
 * (for histograms) bucket boundaries.
 *
 * Names follow OpenTelemetry conventions: lowercase, dot-namespaced,
 * e.g. "http.server.request.duration".
 */
final readonly class MetricDefinition
{
    /**
     * @param  list<float>|null  $buckets
     */
    public function __construct(
        public string $name,
        public MetricType $type,
        public string $description = '',
        public string $unit = '',
        public ?array $buckets = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9._]*$/', $name) !== 1) {
            throw new InvalidMetricName($name);
        }
    }

    /**
     * The Prometheus-safe name (dots become underscores).
     */
    public function prometheusName(): string
    {
        return str_replace('.', '_', $this->name);
    }
}
