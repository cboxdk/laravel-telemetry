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
     * @param  int  $droppedAttributes  attributes the event refused at its
     *                                  limit, carried so OTLP can report
     *                                  them — an event that lost half of
     *                                  what it was told must not look
     *                                  like one that was told half as much
     */
    public function __construct(
        public string $name,
        public int $timeUnixNano,
        public array $attributes = [],
        public int $droppedAttributes = 0,
    ) {}
}
