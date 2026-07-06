<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Tracing;

/**
 * A causal reference to another span that isn't a parent — e.g. a
 * retried job's span linking back to the previous attempt's span,
 * which is a sibling (both children of the original dispatch), not an
 * ancestor.
 */
final readonly class SpanLink
{
    /**
     * @param  array<string, scalar|null>  $attributes
     */
    public function __construct(
        public string $traceId,
        public string $spanId,
        public array $attributes = [],
    ) {}
}
