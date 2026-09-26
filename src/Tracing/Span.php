<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Tracing;

use Cbox\Telemetry\Support\ExceptionAttributes;
use Cbox\Telemetry\Support\TraceParent;
use Closure;
use Throwable;

/**
 * A span. Spans are objects, never looked up by name — two concurrent
 * spans with the same name are two spans.
 *
 * Wall-clock start is anchored once; duration is measured with the
 * monotonic clock, so end timestamps are immune to clock adjustments.
 */
final class Span
{
    /**
     * Per-span limits, matching the OpenTelemetry SDK defaults.
     *
     * A span is built from whatever the application hands it — a loop
     * that annotates per iteration, a job that adds an event per row —
     * and nothing downstream bounds it. Held in memory until the
     * request ends and then serialised whole, an unbounded span is a
     * memory limit hit inside the observability code, which is the one
     * place it must never happen.
     *
     * A small reserve above each limit is kept for this package's own
     * error annotation: an exception event is the most valuable thing
     * on a span, and losing it because a cache instrumentation filled
     * the list would invert the priority.
     */
    private const MAX_ATTRIBUTES = 128;

    private const MAX_EVENTS = 128;

    private const RESERVE = 8;

    /** @var array<string, scalar|null> */
    private array $attributes;

    /** @var list<SpanEvent> */
    private array $events = [];

    private int $droppedAttributes = 0;

    private int $droppedEvents = 0;

    /** @var list<SpanLink> */
    private array $links;

    private bool $customName = false;

    private ?int $recordedException = null;

    private SpanStatus $status = SpanStatus::Unset;

    private ?string $statusDescription = null;

    private readonly int $startUnixNano;

    private readonly int $startMonotonic;

    private ?int $endUnixNano = null;

    private bool $ended = false;

    /**
     * Detail spans (cache ops, fast queries) are droppable at flush time
     * when the trace is healthy and fast — tail detail retention.
     */
    private bool $detail = false;

    /** Whether this span took its ambient dimensions when it started. */
    private bool $contextCaptured = false;

    /** Per-span resource baseline (only when measuring is enabled). */
    private ?float $startCpuMs = null;

    private ?int $startMemoryBytes = null;

    /**
     * @param  array<string, scalar|null>  $attributes
     * @param  Closure(Span): void  $onEnd
     * @param  int|null  $startUnixNano  Backdated start for spans recorded
     *                                   after the fact (e.g. query events).
     * @param  list<SpanLink>  $links  Causal references to related but
     *                                 non-ancestor spans (e.g. a retried
     *                                 job's previous attempt).
     */
    public function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly ?string $parentSpanId,
        public string $name,
        public readonly SpanKind $kind,
        public readonly bool $sampled,
        array $attributes,
        private readonly Closure $onEnd,
        ?int $startUnixNano = null,
        array $links = [],
    ) {
        // Through the same gate as setAttribute(). Assigning the array
        // straight through let `startSpan('x', attributes: $thousand)`
        // past the limit entirely, and reported nothing dropped.
        $this->attributes = [];

        foreach ($attributes as $key => $value) {
            $this->put($key, $value, reserved: false);
        }

        $this->startUnixNano = $startUnixNano ?? (int) (microtime(true) * 1e9);
        $this->startMonotonic = hrtime(true);
        $this->links = $links;
    }

    public function markDetail(): self
    {
        $this->detail = true;

        return $this;
    }

    public function isDetail(): bool
    {
        return $this->detail;
    }

    /**
     * Rename the span — e.g. once the route is resolved and the low-cardinality
     * "GET /users/{id}" form is known.
     */
    public function updateName(string $name): self
    {
        $this->name = $name;
        $this->customName = true;

        return $this;
    }

    /**
     * Whether the span was explicitly renamed after start — the request
     * middleware's default route-pattern rename backs off, so packages
     * with catch-all routes (CMSs, wildcard APIs) can set meaningful
     * names that survive terminate().
     */
    public function hasCustomName(): bool
    {
        return $this->customName;
    }

    /**
     * @param  scalar|null  $value
     */
    public function setAttribute(string $key, mixed $value): self
    {
        return $this->put($key, $value, reserved: false);
    }

    /**
     * @param  scalar|null  $value
     */
    private function put(string $key, mixed $value, bool $reserved): self
    {
        $limit = self::MAX_ATTRIBUTES + ($reserved ? self::RESERVE : 0);

        if (! array_key_exists($key, $this->attributes) && count($this->attributes) >= $limit) {
            $this->droppedAttributes++;

            return $this;
        }

        // No length limit here, deliberately. Cutting a value before
        // redaction sees it destroys the evidence redaction matches on:
        // truncating `…https://alice:hunter2@example.test/` at the `@`
        // leaves a string the userinfo pattern no longer recognises,
        // and the password ships. Length is the redactor's cap to
        // apply, after it has redacted; the memory a long value
        // occupies until then is bounded by the tracer's byte budget,
        // which drops whole spans rather than mutilating one.
        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Attributes this span refused, for OTLP's droppedAttributesCount —
     * a reader must be able to tell a span that had five attributes
     * from one that had five hundred.
     */
    public function droppedAttributes(): int
    {
        return $this->droppedAttributes;
    }

    public function droppedEvents(): int
    {
        return $this->droppedEvents;
    }

    /**
     * Roughly how much memory this span's content occupies.
     *
     * Rough on purpose: the tracer's byte budget is a safety valve, not
     * an accounting system, and an exact measurement would cost more
     * than the thing it protects against. Keys and scalar values are
     * counted flat, with a small constant for the per-attribute
     * overhead PHP's arrays carry.
     */
    public function approximateBytes(): int
    {
        $bytes = strlen($this->name) + 128;

        foreach ($this->attributes as $key => $value) {
            $bytes += strlen($key) + (is_string($value) ? strlen($value) : 8) + 48;
        }

        foreach ($this->events as $event) {
            $bytes += strlen($event->name) + 64;

            foreach ($event->attributes as $key => $value) {
                $bytes += strlen($key) + (is_string($value) ? strlen($value) : 8) + 48;
            }
        }

        return $bytes;
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    public function setAttributes(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->put($key, $value, reserved: false);
        }

        return $this;
    }

    /**
     * Remove an attribute, rather than setting it to null.
     *
     * A null value is not an absence: it survives to the exporter, which
     * serialises it as an empty string. An attribute that no longer applies —
     * `http.request.method_original` after a correction that made the method
     * canonical — has to go, not be blanked.
     */
    public function forgetAttribute(string $key): self
    {
        unset($this->attributes[$key]);

        return $this;
    }

    /**
     * Fill attributes without overwriting — span-specific values always
     * win over ambient context dimensions.
     *
     * @param  array<string, scalar|null>  $defaults
     */
    public function mergeMissingAttributes(array $defaults): self
    {
        foreach ($defaults as $key => $value) {
            if (! isset($this->attributes[$key])) {
                $this->put($key, $value, reserved: false);
            }
        }

        return $this;
    }

    /**
     * Take the ambient dimensions NOW, and refuse any taken later.
     *
     * For a span that outlives the context it was started in — an outgoing
     * call that settles under a different tenant. Merging at creation alone is
     * not enough: the merge at completion protects the keys already here, but
     * still ADDS keys that appeared in between, so a span started under tenant
     * A ended up carrying tenant B's user. A snapshot is all of it or none.
     *
     * @param  array<string, scalar|null>  $attributes
     */
    public function captureContext(array $attributes): self
    {
        $this->mergeMissingAttributes($attributes);

        $this->contextCaptured = true;

        return $this;
    }

    public function hasCapturedContext(): bool
    {
        return $this->contextCaptured;
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    public function addEvent(string $name, array $attributes = []): self
    {
        return $this->record($name, $attributes, reserved: false);
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    private function record(string $name, array $attributes, bool $reserved): self
    {
        $limit = self::MAX_EVENTS + ($reserved ? self::RESERVE : 0);

        if (count($this->events) >= $limit) {
            $this->droppedEvents++;

            return $this;
        }

        // An event's own attribute map is as unbounded as the span's
        // was, and a hundred and twenty-eight events of it is the same
        // problem multiplied.
        if (count($attributes) > self::MAX_ATTRIBUTES) {
            $attributes = array_slice($attributes, 0, self::MAX_ATTRIBUTES, preserve_keys: true);
        }

        $this->events[] = new SpanEvent($name, (int) (microtime(true) * 1e9), $attributes);

        return $this;
    }

    public function recordException(Throwable $exception): self
    {
        return $this->noteException($exception, fail: true);
    }

    /**
     * Attach the exception to this span. Deduplicated by exception identity
     * so an error reported through several paths (JobFailed AND report(),
     * say) lands one event, not three. `$fail` sets the span to Error —
     * unhandled failures fail the span; a handled report() only annotates.
     */
    public function noteException(Throwable $exception, bool $fail): self
    {
        $id = spl_object_id($exception);

        if ($this->recordedException !== $id) {
            $this->recordedException = $id;
            $this->record('exception', ExceptionAttributes::from($exception), reserved: true);
        }

        if ($fail) {
            // semconv asks for `error.type` on anything that failed, and
            // an attribute is the only form a backend can group or filter
            // by — the exception event carries the same class name where
            // only a human reading one span will find it.
            $this->put('error.type', $exception::class, reserved: true);
            $this->setStatus(SpanStatus::Error, $exception->getMessage());
        }

        return $this;
    }

    public function setStatus(SpanStatus $status, ?string $description = null): self
    {
        $this->status = $status;
        $this->statusDescription = $description;

        return $this;
    }

    /**
     * Capture a CPU/memory baseline so end() can attribute this span's
     * own resource cost. Called by the tracer for sampled spans when
     * resource instrumentation is on.
     */
    public function measureResources(): void
    {
        $this->startCpuMs = self::cpuNowMs();
        $this->startMemoryBytes = memory_get_usage(true);
    }

    public function end(?int $endUnixNano = null): void
    {
        if ($this->ended) {
            return;
        }

        $this->ended = true;
        $this->endUnixNano = $endUnixNano ?? $this->startUnixNano + (hrtime(true) - $this->startMonotonic);

        if ($this->startCpuMs !== null) {
            // ??= — a unit-of-work measurement (request/job/task) on the
            // root span always wins over the span's own numbers.
            $this->attributes['php.cpu.time_ms'] ??= round(max(0.0, self::cpuNowMs() - $this->startCpuMs), 3);
        }

        if ($this->startMemoryBytes !== null) {
            // Allocation delta (may be negative when memory is freed) —
            // honest per-span attribution; a true peak only exists per
            // unit of work.
            $this->attributes['php.memory.delta_bytes'] ??= memory_get_usage(true) - $this->startMemoryBytes;
        }

        ($this->onEnd)($this);
    }

    private static function cpuNowMs(): float
    {
        if (! function_exists('getrusage')) {
            return 0.0;
        }

        $usage = getrusage();

        if ($usage === false) {
            return 0.0;
        }

        // Defensive: getrusage() only returns a partial set of keys on some
        // platforms (e.g. Windows) — never let a missing key throw here.
        return (($usage['ru_utime.tv_sec'] ?? 0) + ($usage['ru_stime.tv_sec'] ?? 0)) * 1000.0
            + (($usage['ru_utime.tv_usec'] ?? 0) + ($usage['ru_stime.tv_usec'] ?? 0)) / 1000.0;
    }

    public function hasEnded(): bool
    {
        return $this->ended;
    }

    public function traceParent(): TraceParent
    {
        return new TraceParent($this->traceId, $this->spanId, $this->sampled);
    }

    /**
     * @return array<string, scalar|null>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * @return list<SpanEvent>
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * @return list<SpanLink>
     */
    public function links(): array
    {
        return $this->links;
    }

    /**
     * @internal used by the redaction engine at flush time
     *
     * @param  list<SpanEvent>  $events
     */
    public function replaceEvents(array $events): void
    {
        $this->events = $events;
    }

    /**
     * @internal used by the redaction engine at flush time
     *
     * @param  list<SpanLink>  $links
     */
    public function replaceLinks(array $links): void
    {
        $this->links = $links;
    }

    public function status(): SpanStatus
    {
        return $this->status;
    }

    public function statusDescription(): ?string
    {
        return $this->statusDescription;
    }

    public function startUnixNano(): int
    {
        return $this->startUnixNano;
    }

    public function endUnixNano(): int
    {
        return $this->endUnixNano ?? $this->startUnixNano;
    }

    public function durationMs(): float
    {
        return ($this->endUnixNano() - $this->startUnixNano) / 1_000_000;
    }
}
