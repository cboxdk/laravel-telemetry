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

    /** @var array<string, scalar|null> ambient dimensions merged into every finished span */
    private array $contextAttributes = [];

    private bool $measureSpanResources = false;

    /** @var array<string, float> tallies attached to the root span when it ends */
    private array $traceStats = [];

    /**
     * A mid-trace re-decision (per-route Sample middleware). Overrides
     * the head decision for buffering and propagation.
     */
    private ?bool $sampledOverride = null;

    public function __construct(
        private readonly float $sampleRate = 1.0,
        private readonly int $maxBuffer = 5000,
        private readonly bool $alwaysSampleErrors = true,
    ) {}

    /**
     * Re-decide sampling for the ACTIVE trace (per-route overrides).
     * Spans that already finished under the old decision are unaffected;
     * everything from now on — including the still-open root span —
     * follows the new one.
     */
    public function resample(bool $sampled): void
    {
        $this->sampledOverride = $sampled;
        $this->sampled = $sampled;
    }

    /**
     * Re-decide with a rate (0–1), using the same lottery as the head
     * decision.
     */
    public function resampleAt(float $rate): void
    {
        $this->resample(match (true) {
            $rate >= 1.0 => true,
            $rate <= 0.0 => false,
            default => (mt_rand() / mt_getrandmax()) < $rate,
        });
    }

    /**
     * Give every sampled span its own CPU-time and memory-delta
     * attributes (a getrusage + memory_get_usage pair per span).
     */
    public function measureSpanResources(bool $enabled = true): void
    {
        $this->measureSpanResources = $enabled;
    }

    /**
     * Add ambient context dimensions (team, tenant, plan, …). They merge
     * into every span that finishes from now on — span-specific
     * attributes win on conflict.
     *
     * @param  array<string, scalar|null>  $attributes
     */
    public function addContext(array $attributes): void
    {
        $this->contextAttributes = [...$this->contextAttributes, ...$attributes];
    }

    /**
     * @return array<string, scalar|null>
     */
    public function contextAttributes(): array
    {
        return $this->contextAttributes;
    }

    /**
     * The root of the active context — e.g. the request span, useful as
     * the origin name for dispatched work.
     */
    public function rootSpan(): ?Span
    {
        return $this->stack[0] ?? null;
    }

    /**
     * Continue a trace started elsewhere (incoming request, queued job).
     *
     * With $trustSampling disabled, the remote trace/span ids are kept for
     * correlation but the sampling decision is made locally — callers
     * cannot force sampling on or off.
     */
    public function continueFrom(TraceParent $parent, bool $trustSampling = true): void
    {
        $this->remoteParent = $parent;
        $this->traceId = $parent->traceId;
        $this->sampled = $trustSampling ? $parent->sampled : null;
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

        if ($this->measureSpanResources && $span->sampled) {
            $span->measureResources();
        }

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
    public function recordSpan(string $name, float $durationMs, array $attributes = [], SpanKind $kind = SpanKind::Internal, bool $detail = false): Span
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

        if ($detail) {
            $span->markDetail();
        }

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
            return new TraceParent(
                $current->traceId,
                $current->spanId,
                $this->sampledOverride ?? $current->sampled,
            );
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
        $this->sampledOverride = null;
        $this->contextAttributes = [];
        $this->traceStats = [];
    }

    /**
     * Accumulate a per-trace tally (query count, query time, …). The
     * totals land as attributes on the ROOT span when it ends — Nightwatch
     * shows "4 queries / 24.5 ms" per request; so do we.
     */
    public function bumpStat(string $attribute, float $delta): void
    {
        $this->traceStats[$attribute] = ($this->traceStats[$attribute] ?? 0.0) + $delta;
    }

    private function finish(Span $span): void
    {
        // Remove wherever it sits — out-of-order ends must not corrupt
        // the context stack.
        $index = array_search($span, $this->stack, true);

        if ($index !== false) {
            array_splice($this->stack, (int) $index, 1);
        }

        $sampled = $this->sampledOverride ?? $span->sampled;

        if (! $sampled) {
            // Error spans escape sampling: from a 10%-sampled app you
            // still get every failing span (its trace may be partial —
            // siblings were dropped under the head decision).
            if (! $this->alwaysSampleErrors || $span->status() !== SpanStatus::Error) {
                return;
            }
        }

        if ($this->contextAttributes !== []) {
            $span->mergeMissingAttributes($this->contextAttributes);
        }

        // The root just ended: attach the per-trace tallies.
        if ($this->stack === [] && $this->traceStats !== []) {
            $stats = [];

            foreach ($this->traceStats as $key => $value) {
                $stats[$key] = fmod($value, 1.0) === 0.0 ? (int) $value : round($value, 2);
            }

            $span->mergeMissingAttributes($stats);
            $this->traceStats = [];
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
