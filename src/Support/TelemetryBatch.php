<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

use Cbox\Telemetry\Events\TelemetryEvent;
use Cbox\Telemetry\Metrics\MetricFamily;
use Cbox\Telemetry\Tracing\Span;

/**
 * A batch of telemetry handed to an exporter, together with the resource
 * attributes identifying this service.
 */
final readonly class TelemetryBatch
{
    /**
     * @param  array<string, scalar>  $resource
     * @param  list<Span>  $spans
     * @param  list<MetricFamily>  $metrics
     * @param  list<TelemetryEvent>  $events
     */
    public function __construct(
        public array $resource = [],
        public array $spans = [],
        public array $metrics = [],
        public array $events = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->spans === [] && $this->metrics === [] && $this->events === [];
    }

    /**
     * Narrow the batch to the signals an exporter supports.
     */
    public function only(SignalSet $signals): self
    {
        return new self(
            resource: $this->resource,
            spans: $signals->contains(Signal::Traces) ? $this->spans : [],
            metrics: $signals->contains(Signal::Metrics) ? $this->metrics : [],
            events: $signals->contains(Signal::Events) ? $this->events : [],
        );
    }
}
