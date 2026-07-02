<?php

declare(strict_types=1);

namespace Cbox\Telemetry;

use Cbox\Telemetry\Contracts\Exporter;
use Cbox\Telemetry\Contracts\TelemetryProvider;
use Cbox\Telemetry\Events\TelemetryEvent;
use Cbox\Telemetry\Metrics\Instruments\Counter;
use Cbox\Telemetry\Metrics\Instruments\Gauge;
use Cbox\Telemetry\Metrics\Instruments\Histogram;
use Cbox\Telemetry\Metrics\Instruments\ObservableGauge;
use Cbox\Telemetry\Metrics\MetricFamily;
use Cbox\Telemetry\Metrics\Registry;
use Cbox\Telemetry\Metrics\Stores\BufferedMetricStore;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\Support\Signal;
use Cbox\Telemetry\Support\TelemetryBatch;
use Cbox\Telemetry\Support\TraceParent;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\Tracer;
use Closure;
use Illuminate\Http\Request;

/**
 * The telemetry entry point, resolved behind the Telemetry facade.
 *
 * Providers register lazily — only when a consumer (scrape, flush) needs
 * the instruments. Spans and events buffer in memory and flush once at
 * terminate; metrics live in the shared store and are pulled on demand.
 */
class TelemetryManager
{
    /** @var list<TelemetryProvider> */
    private array $pendingProviders = [];

    private int $bootedProviders = 0;

    /** @var list<Exporter> */
    private array $exporters = [];

    /** @var list<TelemetryEvent> */
    private array $events = [];

    private bool $flushing = false;

    private ?Closure $requestLabelResolver = null;

    private ?Closure $userAttributeResolver = null;

    /**
     * @param  array<string, scalar>  $resource
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly Registry $registry,
        private readonly Tracer $tracer,
        private readonly array $resource = [],
        private readonly int $maxBufferedEvents = 5000,
    ) {
        $this->tracer->onBufferFull(fn () => $this->flush());
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /*
    |--------------------------------------------------------------------------
    | Instruments
    |--------------------------------------------------------------------------
    */

    public function counter(string $name, string $description = '', string $unit = ''): Counter
    {
        return $this->registry->counter($name, $description, $unit);
    }

    /**
     * Without a callback: a push gauge (`->set(42)`).
     * With a callback: an observable gauge evaluated at scrape time.
     */
    public function gauge(
        string $name,
        ?Closure $callback = null,
        string $description = '',
        string $unit = '',
    ): Gauge|ObservableGauge {
        return $this->registry->gauge($name, $callback, $description, $unit);
    }

    /**
     * @param  list<float>|null  $buckets
     */
    public function histogram(
        string $name,
        ?array $buckets = null,
        string $description = '',
        string $unit = '',
    ): Histogram {
        return $this->registry->histogram($name, $buckets, $description, $unit);
    }

    /*
    |--------------------------------------------------------------------------
    | Spans
    |--------------------------------------------------------------------------
    */

    /**
     * With a callback, measures it inside the span and returns its result;
     * exceptions are recorded and rethrown. Without a callback, returns a
     * started Span you must ->end() yourself.
     *
     * @template T
     *
     * @param  (Closure(Span): T)|null  $callback
     * @param  array<string, scalar|null>  $attributes
     * @return ($callback is null ? Span : T)
     */
    public function span(
        string $name,
        ?Closure $callback = null,
        array $attributes = [],
        SpanKind $kind = SpanKind::Internal,
    ): mixed {
        if ($callback === null) {
            return $this->tracer->startSpan($name, $kind, $attributes);
        }

        return $this->tracer->span($name, $callback, $attributes, $kind);
    }

    public function currentSpan(): ?Span
    {
        return $this->tracer->currentSpan();
    }

    public function traceId(): ?string
    {
        return $this->tracer->traceId();
    }

    /**
     * The W3C traceparent header value to propagate downstream, or null
     * when no trace is active.
     */
    public function traceparent(): ?string
    {
        return $this->tracer->currentTraceParent()?->toString();
    }

    /**
     * Continue a trace from an incoming W3C traceparent header.
     *
     * With $trustSampling disabled, the caller's ids are kept for
     * correlation but the sampling decision is made locally.
     */
    public function continueTrace(?string $traceparent, bool $trustSampling = true): void
    {
        $parent = TraceParent::parse($traceparent);

        if ($parent !== null) {
            $this->tracer->continueFrom($parent, $trustSampling);
        }
    }

    /**
     * Forget the active trace context (between Octane requests / jobs).
     */
    public function resetContext(): void
    {
        $this->tracer->resetContext();
    }

    /**
     * Add custom dimensions (team, tenant, plan, …) that merge into every
     * span, event and telemetry-channel log record for the rest of the
     * request/job — and travel with dispatched jobs:
     *
     *     Telemetry::context(['team.id' => $team->id, 'plan' => $plan]);
     *
     * Traces/events/logs only — never metric labels (cardinality safety);
     * use labelRequestsUsing() for bounded metric dimensions.
     *
     * @param  array<string, scalar|null>  $attributes
     */
    public function context(array $attributes): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->tracer->addContext($attributes);
    }

    /**
     * @return array<string, scalar|null>
     */
    public function contextAttributes(): array
    {
        return $this->tracer->contextAttributes();
    }

    /**
     * Add BOUNDED extra labels (plan, tier, team — never ids with
     * unbounded cardinality) to the http.server.request.duration metric:
     *
     *     Telemetry::labelRequestsUsing(fn ($request) => [
     *         'plan' => $request->user()?->plan ?? 'guest',
     *     ]);
     *
     * Enables p95/p99 per plan/team in PromQL.
     *
     * @param  (Closure(Request): array<string, scalar|null>)|null  $resolver
     */
    public function labelRequestsUsing(?Closure $resolver): void
    {
        $this->requestLabelResolver = $resolver;
    }

    /**
     * @internal used by the request middleware
     *
     * @return array<string, scalar|null>
     */
    public function resolveRequestLabels(mixed $request): array
    {
        if ($this->requestLabelResolver === null) {
            return [];
        }

        return FailSafe::guard(fn (): array => ($this->requestLabelResolver)($request)) ?? [];
    }

    /**
     * Opt in to richer user attribution on request spans (PII is off by
     * default — only enduser.id ships out of the box):
     *
     *     Telemetry::resolveUserUsing(fn ($user) => [
     *         'enduser.name' => $user->name,
     *     ]);
     *
     * @param  (Closure(mixed): array<string, scalar|null>)|null  $resolver
     */
    public function resolveUserUsing(?Closure $resolver): void
    {
        $this->userAttributeResolver = $resolver;
    }

    /**
     * @internal used by the request middleware
     *
     * @return array<string, scalar|null>
     */
    public function resolveUserAttributes(mixed $user): array
    {
        if ($this->userAttributeResolver === null) {
            return [];
        }

        return FailSafe::guard(fn (): array => ($this->userAttributeResolver)($user)) ?? [];
    }

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    */

    /**
     * Emit a structured event, correlated to the active trace.
     *
     * @param  array<string, scalar|null>  $attributes
     */
    public function event(string $name, array $attributes = []): void
    {
        $span = $this->tracer->currentSpan();

        $this->recordEvent(new TelemetryEvent(
            name: $name,
            timeUnixNano: (int) (microtime(true) * 1e9),
            // Ambient context dimensions ride along; explicit wins.
            attributes: [...$this->tracer->contextAttributes(), ...$attributes],
            traceId: $span->traceId ?? $this->tracer->traceId(),
            spanId: $span?->spanId,
        ));
    }

    /**
     * Buffer a pre-built event (used by the `telemetry` log channel).
     *
     * Events emitted while a flush is exporting are dropped — a failing
     * exporter that logs through the telemetry channel must not feed
     * itself.
     */
    public function recordEvent(TelemetryEvent $event): void
    {
        if (! $this->enabled || $this->flushing) {
            return;
        }

        $this->events[] = $event;

        // Cap like the span buffer — long-running workers must not grow
        // memory without bound.
        if (count($this->events) >= $this->maxBufferedEvents) {
            $this->flush();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    */

    /**
     * Register a telemetry provider. Registration is lazy: register() runs
     * the first time instruments are actually needed.
     */
    public function provider(TelemetryProvider $provider): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->pendingProviders[] = $provider;
    }

    /**
     * Inline provider registration for quick package integrations:
     *
     *     Telemetry::contributes('my-package', function (Registry $registry) {
     *         $registry->gauge('my_package.things', fn () => Thing::count());
     *     });
     *
     * @param  Closure(Registry): void  $register
     */
    public function contributes(string $name, Closure $register): void
    {
        $this->provider(new InlineProvider($name, $register));
    }

    /*
    |--------------------------------------------------------------------------
    | Export
    |--------------------------------------------------------------------------
    */

    public function addExporter(Exporter $exporter): void
    {
        $this->exporters[] = $exporter;
    }

    /**
     * Flush buffered spans and events to every exporter that supports them.
     * Called from terminable middleware and after each queue job.
     */
    public function flush(): void
    {
        // Buffered stores push their aggregated metric writes at the same
        // points spans flush — request terminate, after each queue job —
        // even when no spans or events are pending.
        $store = $this->registry->store();

        if ($store instanceof BufferedMetricStore) {
            FailSafe::guard(fn () => $store->flushBuffer());
        }

        $spans = $this->tracer->drain();
        $events = $this->events;
        $this->events = [];

        if ($spans === [] && $events === []) {
            return;
        }

        $this->flushing = true;

        try {
            $this->export(new TelemetryBatch(
                resource: $this->resource,
                spans: $spans,
                events: $events,
            ), Signal::Traces, Signal::Events);
        } finally {
            $this->flushing = false;
        }
    }

    /**
     * Push metrics from the shared store (plus observable gauges) to every
     * exporter that supports metrics. Run by the `telemetry:flush` command.
     */
    public function flushMetrics(): int
    {
        $metrics = $this->collect();

        if ($metrics === []) {
            return 0;
        }

        $this->export(new TelemetryBatch(
            resource: $this->resource,
            metrics: $metrics,
        ), Signal::Metrics);

        return count($metrics);
    }

    /**
     * Every current metric family — used by the Prometheus endpoint and
     * the OTLP metrics flush.
     *
     * @return list<MetricFamily>
     */
    public function collect(): array
    {
        if (! $this->enabled) {
            return [];
        }

        $this->bootProviders();

        return $this->registry->collect();
    }

    /*
    |--------------------------------------------------------------------------
    | Introspection & configuration
    |--------------------------------------------------------------------------
    */

    public function registry(): Registry
    {
        $this->bootProviders();

        return $this->registry;
    }

    public function tracer(): Tracer
    {
        return $this->tracer;
    }

    /**
     * @return array<string, scalar>
     */
    public function resource(): array
    {
        return $this->resource;
    }

    /**
     * @return list<Exporter>
     */
    public function exporters(): array
    {
        return $this->exporters;
    }

    /**
     * @param  Closure(\Throwable): void|null  $handler
     */
    public function handleExceptionsUsing(?Closure $handler): void
    {
        FailSafe::handleExceptionsUsing($handler);
    }

    private function bootProviders(): void
    {
        // Only providers added since the last boot run — register() must
        // never execute twice for the same provider.
        while ($this->bootedProviders < count($this->pendingProviders)) {
            $provider = $this->pendingProviders[$this->bootedProviders++];

            FailSafe::guard(fn () => $provider->register($this->registry));
        }
    }

    private function export(TelemetryBatch $batch, Signal ...$signals): void
    {
        foreach ($this->exporters as $exporter) {
            $supports = $exporter->supports();

            $relevant = false;

            foreach ($signals as $signal) {
                if ($supports->contains($signal)) {
                    $relevant = true;
                    break;
                }
            }

            if (! $relevant) {
                continue;
            }

            $narrowed = $batch->only($supports);

            if (! $narrowed->isEmpty()) {
                FailSafe::guard(fn () => $exporter->export($narrowed));
            }
        }
    }
}
