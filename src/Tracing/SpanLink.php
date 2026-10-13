<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Tracing;

/**
 * A causal reference to another span that isn't a parent — e.g. a
 * retried job's span linking back to the previous attempt's span,
 * which is a sibling (both children of the original dispatch), not an
 * ancestor; or a linked job's root span pointing at the span that
 * dispatched it.
 *
 * $sampled is the linked span context's sampled flag, exported as the
 * link's W3C trace flags when known.
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
        public ?bool $sampled = null,
    ) {}
}
