<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Events;

/**
 * A structured, timestamped event — exported as an OTLP log record,
 * correlated to the active trace when one exists.
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
    ) {}
}
