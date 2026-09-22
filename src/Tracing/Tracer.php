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

    /** @var array<string, scalar> ambient dimensions merged into every finished span; a null passed to addContext() removes the key rather than storing one */
    private array $contextAttributes = [];

    private bool $measureSpanResources = false;

    /** @var array<string, float> tallies attached to the root span when it ends */
    private array $traceStats = [];

    /**
     * A mid-trace re-decision (per-route Sample middleware). Overrides
     * the head decision for buffering and propagation.
     */
    private ?bool $sampledOverride = null;

    /**
     * The active unit of work is not traced at all (an ignored request
     * path). Stronger than an unsampled trace — see suppress().
     */
    private bool $suppressed = false;

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
    /**
     * The sampling POLICY in force for the active trace right now: a
     * per-route override if one was made, otherwise the head decision —
     * which is taken lazily, so a trace that has not started yet reads as
     * sampled.
     *
     * Ask it at the END of a unit of work, not at the start, when deciding
     * whether to keep expensive per-trace work (a CPU profile, say): the
     * decision can change mid-request.
     *
     * Pass the span whose fate you are actually asking about and the answer
     * applies the SAME rule `finish()` applies to it, error escape included
     * (`traces.always_sample_errors`) — one definition of "will this be
     * exported", rather than a second one drifting in a caller.
     */
    public function currentlySampled(?Span $span = null): bool
    {
        if ($this->suppressed) {
            return false;
        }

        $sampled = $span === null
            ? $this->sampledOverride ?? $this->sampled ?? true
            : $this->sampledOverride ?? $span->sampled;

        if ($sampled) {
            return true;
        }

        return $this->alwaysSampleErrors && $span?->status() === SpanStatus::Error;
    }

    /**
     * Take the active unit of work out of tracing entirely — for requests
     * the host asked not to instrument (`instrument.http_ignore_paths`).
     *
     * Stronger than resample(false), on purpose:
     *
     * - Spans still open as CONTEXT, so work inside the unit (a query, an
     *   outgoing HTTP call, a mail) finds a parent and never starts a trace
     *   root of its own. Nothing is buffered, not even an error span — the
     *   `always_sample_errors` escape would otherwise export a failing child
     *   whose parent was never recorded: an orphan, which is the noise this
     *   exists to remove.
     * - No trace id is exposed (traceId() is null) and nothing propagates
     *   (currentTraceParent() is null). Exception records and log lines
     *   written during the unit carry no trace reference to a trace that
     *   does not exist, and a job dispatched or a service called from it
     *   starts its own trace instead of inheriting an unsampled one.
     *
     * Cleared by resetContext(), which every unit of work ends with.
     */
    public function suppress(): void
    {
        $this->suppressed = true;
        $this->sampled = false;
        $this->sampledOverride = false;
    }

    public function suppressed(): bool
    {
        return $this->suppressed;
    }

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
     * A null value REMOVES the dimension rather than recording an empty one:
     * spans take context through mergeMissingAttributes(), whose ??= creates
     * the key even for a null, and the OTLP serializer has no null — it ships
     * an empty string. So a caller mirroring "the current tenant, or none"
     * would otherwise stamp an empty attribute on every span outside a tenant,
     * and had to filter nulls itself to avoid it. Null meaning "not set" also
     * gives callers the only way to clear a dimension mid-unit-of-work; before
     * this, resetContext() — which drops the trace continuation with it — was
     * the only lever.
     *
     * @param  array<string, scalar|null>  $attributes
     */
    public function addContext(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            if ($value === null) {
                unset($this->contextAttributes[$key]);

                continue;
            }

            $this->contextAttributes[$key] = $value;
        }
    }

    /**
     * @return array<string, scalar>
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
     * @param  list<SpanLink>  $links  Causal references to related but
     *                                 non-ancestor spans (e.g. a retried
     *                                 job's previous attempt).
     */
    public function startSpan(string $name, SpanKind $kind = SpanKind::Internal, array $attributes = [], array $links = []): Span
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
            links: $links,
        );

        if ($this->measureSpanResources && $span->sampled) {
            $span->measureResources();
        }

        $this->stack[] = $span;

        return $span;
    }

    /**
     * Start a span that does NOT become the ambient context.
     *
     * The ambient stack models "what is happening right now, here". An
     * outgoing HTTP call does not fit that: several can be open at once
     * (`Http::pool`), and a promise can be created under one parent and
     * awaited under another. Pushing those onto the stack made each pooled
     * request a CHILD of the one dispatched before it, and left whatever ran
     * next parented to a call that had not finished.
     *
     * So the parent is fixed at creation and the span is handed back to
     * whoever owns it, to be ended when that owner's own work completes. It
     * still ends through finish(), so sampling, context attributes and the
     * buffer behave exactly as for any other span.
     *
     * @param  array<string, scalar|null>  $attributes
     */
    public function startDetachedSpan(string $name, SpanKind $kind = SpanKind::Internal, array $attributes = []): Span
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

        // The ambient dimensions are taken NOW, not at finish(). A detached
        // span can be created under one tenant and settle under another —
        // that is the whole point of detaching it — and finish() merges
        // whatever the tracer holds at the moment the span ends. Merged here
        // as missing attributes, so the span carries the context it was
        // started in and the later merge is a no-op for those keys.
        $span->captureContext($this->contextAttributes);

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

    /**
     * End every span still on the stack, innermost first.
     *
     * For the shutdown path only. A fatal error — max_execution_time, an
     * allocation over memory_limit, an uncaught Error — unwinds nothing: the
     * request span and every child stay open, so `drain()` returns them not at
     * all and the trace for the request that actually died is the one trace
     * missing. Ending them marks the work as what it was rather than pretending
     * it completed.
     *
     * @return int how many spans were still open
     */
    public function endOpenSpans(string $reason): int
    {
        $closed = 0;

        while (($span = $this->currentSpan()) !== null) {
            $span->setStatus(SpanStatus::Error, $reason);
            $span->end();
            $closed++;

            // end() splices the span out through finish(); guard against a
            // span that somehow fails to leave, so shutdown cannot hang.
            if ($this->currentSpan() === $span) {
                array_pop($this->stack);
            }
        }

        // Detached spans are deliberately NOT closed here. Shutdown runs on
        // every request, before the callbacks an application registers to await
        // its own outstanding work — so closing them stamped a call that went
        // on to return 200 as an error lasting a third of a millisecond, and
        // its real completion could no longer update or export it. Holding
        // them to close later was worse still: nothing else referenced a
        // cancelled call's span, so tracking them kept 30,000 of them alive at
        // 26MB where the garbage collector had been freeing them.
        //
        // A missing span is the lesser evil. A span with the wrong duration and
        // status is a lie that reads as data.

        return $closed;
    }

    public function traceId(): ?string
    {
        if ($this->suppressed) {
            return null;
        }

        return $this->currentSpan()->traceId ?? $this->traceId;
    }

    /**
     * The traceparent to propagate to downstream services and queued jobs.
     */
    public function currentTraceParent(): ?TraceParent
    {
        if ($this->suppressed) {
            return null;
        }

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
        $this->suppressed = false;
        $this->contextAttributes = [];
        $this->traceStats = [];
    }

    /**
     * Accumulate a per-trace tally (query count, query time, …). The
     * totals land as attributes on the ROOT span when it ends, so every
     * request shows its "4 queries / 24.5 ms" summary without needing
     * the detail spans.
     */
    public function bumpStat(string $attribute, float $delta): void
    {
        $this->traceStats[$attribute] = ($this->traceStats[$attribute] ?? 0.0) + $delta;
    }

    /**
     * Drop a span that will never be answered, without exporting it.
     *
     * For an instrumentation that opened a span and then learned it can never
     * match an outcome to it — a redirect hop, a request whose wrapper was
     * replaced. Leaving it on the stack is not neutral: the shutdown path ends
     * every open span as an error lasting until the process died, so a healthy
     * redirected call would publish a failed span with a fabricated duration.
     * A missing span is the lesser evil; a span with the wrong duration and
     * status is a lie that reads as data.
     *
     * The span is removed from the context stack and never buffered, so its
     * children keep the parent id of a span that is not exported — which is
     * what already happens today, and is why this is a stopgap rather than the
     * fix.
     */
    public function discardSpan(Span $span): void
    {
        $index = array_search($span, $this->stack, true);

        if ($index !== false) {
            array_splice($this->stack, (int) $index, 1);
        }
    }

    private function finish(Span $span): void
    {
        // Remove wherever it sits — out-of-order ends must not corrupt
        // the context stack.
        $index = array_search($span, $this->stack, true);

        if ($index !== false) {
            array_splice($this->stack, (int) $index, 1);
        }

        // A suppressed unit exports nothing — no error escape either.
        if ($this->suppressed) {
            return;
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

        // A span that took its dimensions when it started keeps exactly those.
        // Merging here too still ADDED whatever appeared in between, so a call
        // begun under one tenant carried the next tenant's user.
        if ($this->contextAttributes !== [] && ! $span->hasCapturedContext()) {
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
