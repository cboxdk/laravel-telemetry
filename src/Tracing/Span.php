<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Tracing;

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
    /** @var array<string, scalar|null> */
    private array $attributes;

    /** @var list<SpanEvent> */
    private array $events = [];

    private bool $customName = false;

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

    /** Per-span resource baseline (only when measuring is enabled). */
    private ?float $startCpuMs = null;

    private ?int $startMemoryBytes = null;

    /**
     * @param  array<string, scalar|null>  $attributes
     * @param  Closure(Span): void  $onEnd
     * @param  int|null  $startUnixNano  Backdated start for spans recorded
     *                                   after the fact (e.g. query events).
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
    ) {
        $this->attributes = $attributes;
        $this->startUnixNano = $startUnixNano ?? (int) (microtime(true) * 1e9);
        $this->startMonotonic = hrtime(true);
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
        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    public function setAttributes(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }

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
            $this->attributes[$key] ??= $value;
        }

        return $this;
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    public function addEvent(string $name, array $attributes = []): self
    {
        $this->events[] = new SpanEvent($name, (int) (microtime(true) * 1e9), $attributes);

        return $this;
    }

    public function recordException(Throwable $exception): self
    {
        $this->addEvent('exception', [
            'exception.type' => $exception::class,
            'exception.message' => $exception->getMessage(),
            'exception.stacktrace' => $exception->getTraceAsString(),
        ]);

        return $this->setStatus(SpanStatus::Error, $exception->getMessage());
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

        return ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1000.0
            + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1000.0;
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
     * @internal used by the redaction engine at flush time
     *
     * @param  list<SpanEvent>  $events
     */
    public function replaceEvents(array $events): void
    {
        $this->events = $events;
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
