<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Events;

/**
 * A structured, timestamped event — exported as an OTLP log record,
 * correlated to the active trace when one exists.
 *
 * Both Telemetry::event() and the `telemetry` log channel produce these;
 * log records carry their Monolog severity, events default to INFO.
 */
final readonly class TelemetryEvent
{
    /**
     * @param  array<string, scalar|null>  $attributes
     */
    public function __construct(
        public string $name,
        public int $timeUnixNano,
        public array $attributes = [],
        public ?string $traceId = null,
        public ?string $spanId = null,
        public ?int $severityNumber = null,
        public ?string $severityText = null,
    ) {}
}
