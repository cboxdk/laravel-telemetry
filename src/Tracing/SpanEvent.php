<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Tracing;

/**
 * A timestamped event attached to a span (e.g. a recorded exception).
 */
final readonly class SpanEvent
{
    /**
     * @param  array<string, scalar|null>  $attributes
     */
    public function __construct(
        public string $name,
        public int $timeUnixNano,
        public array $attributes = [],
    ) {}
}
