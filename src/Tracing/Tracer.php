<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Tracing;

use Cbox\Telemetry\Support\Ids;
use Cbox\Telemetry\Support\TraceParent;
use Closure;
use Throwable;

/**
 * Owns the active span context and the in-memory buffer of finished spans.
 *
 * The sample decision is made once, at the trace root, and inherited by
 * every child (and by remote callers via the traceparent sampled flag).
 * Unsampled spans still exist as context — they propagate ids — but are
 * never buffered or exported.
 */
final class Tracer
{
    /** @var list<Span> */
    private array $finished = [];

    /** @var list<Span> */
    private array $stack = [];

    private ?TraceParent $remoteParent = null;

    private ?string $traceId = null;

    private ?bool $sampled = null;

    private ?Closure $onBufferFull = null;

    public function __construct(
        private readonly float $sampleRate = 1.0,
        private readonly int $maxBuffer = 5000,
    ) {}

    /**
     * Continue a trace started elsewhere (incoming request, queued job).
     */
    public function continueFrom(TraceParent $parent): void
    {
        $this->remoteParent = $parent;
        $this->traceId = $parent->traceId;
        $this->sampled = $parent->sampled;
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    public function startSpan(string $name, SpanKind $kind = SpanKind::Internal, array $attributes = []): Span
    {
        $parent = $this->currentSpan();

        if ($this->traceId === null) {
            $this->traceId = Ids::traceId();
        }

        $this->sampled ??= $this->lottery();

        $span = new Span(
            traceId: $this->traceId,
            spanId: Ids::spanId(),
            parentSpanId: $parent->spanId ?? $this->remoteParent?->spanId,
            name: $name,
            kind: $kind,
            sampled: $this->sampled,
            attributes: $attributes,
            onEnd: fn (Span $span) => $this->finish($span),
        );

        $this->stack[] = $span;

        return $span;
    }

    /**
     * Measure a closure inside a span. Exceptions are recorded on the span
     * and rethrown.
     *
     * @template T
     *
     * @param  Closure(Span): T  $callback
     * @param  array<string, scalar|null>  $attributes
     * @return T
     */
    public function span(string $name, Closure $callback, array $attributes = [], SpanKind $kind = SpanKind::Internal): mixed
    {
        $span = $this->startSpan($name, $kind, $attributes);

        try {
            $result = $callback($span);

            if ($span->status() === SpanStatus::Unset) {
                $span->setStatus(SpanStatus::Ok);
            }

            return $result;
        } catch (Throwable $e) {
            $span->recordException($e);

            throw $e;
        } finally {
            $span->end();
        }
    }

    /**
     * Record a span for work that already happened (e.g. a query event
     * reporting its own duration). The span is backdated and closed
     * immediately, parented to the current span.
     *
     * @param  array<string, scalar|null>  $attributes
     */
    public function recordSpan(string $name, float $durationMs, array $attributes = [], SpanKind $kind = SpanKind::Internal): Span
    {
        $durationNano = (int) ($durationMs * 1_000_000);
        $start = (int) (microtime(true) * 1e9) - $durationNano;

        if ($this->traceId === null) {
            $this->traceId = Ids::traceId();
        }

        $this->sampled ??= $this->lottery();

        $span = new Span(
            traceId: $this->traceId,
            spanId: Ids::spanId(),
            parentSpanId: $this->currentSpan()->spanId ?? $this->remoteParent?->spanId,
            name: $name,
            kind: $kind,
            sampled: $this->sampled,
            attributes: $attributes,
            onEnd: fn (Span $ended) => $this->finish($ended),
            startUnixNano: $start,
        );

        $span->end($start + $durationNano);

        return $span;
    }

    public function currentSpan(): ?Span
    {
        return $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];
    }

    public function traceId(): ?string
    {
        return $this->currentSpan()->traceId ?? $this->traceId;
    }

    /**
     * The traceparent to propagate to downstream services and queued jobs.
     */
    public function currentTraceParent(): ?TraceParent
    {
        if ($current = $this->currentSpan()) {
            return $current->traceParent();
        }

        return $this->remoteParent;
    }

    /**
     * Drain the finished-span buffer for export.
     *
     * @return list<Span>
     */
    public function drain(): array
    {
        $spans = $this->finished;
        $this->finished = [];

        return $spans;
    }

    public function bufferedCount(): int
    {
        return count($this->finished);
    }

    /**
     * Invoked when the buffer exceeds max size — the manager hooks a
     * flush here so long-running workers can't grow unbounded.
     *
     * @param  Closure(): void  $callback
     */
    public function onBufferFull(Closure $callback): void
    {
        $this->onBufferFull = $callback;
    }

    /**
     * Forget the current trace context (between Octane requests / jobs).
     * Finished-but-unflushed spans are kept.
     */
    public function resetContext(): void
    {
        $this->stack = [];
        $this->remoteParent = null;
        $this->traceId = null;
        $this->sampled = null;
    }

    private function finish(Span $span): void
    {
        // Remove wherever it sits — out-of-order ends must not corrupt
        // the context stack.
        $index = array_search($span, $this->stack, true);

        if ($index !== false) {
            array_splice($this->stack, (int) $index, 1);
        }

        if (! $span->sampled) {
            return;
        }

        $this->finished[] = $span;

        if (count($this->finished) >= $this->maxBuffer && $this->onBufferFull !== null) {
            ($this->onBufferFull)();
        }
    }

    private function lottery(): bool
    {
        if ($this->sampleRate >= 1.0) {
            return true;
        }

        if ($this->sampleRate <= 0.0) {
            return false;
        }

        return (mt_rand() / mt_getrandmax()) < $this->sampleRate;
    }
}
