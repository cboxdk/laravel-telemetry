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
use Cbox\Telemetry\Support\ExportResult;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\Support\Redactor;
use Cbox\Telemetry\Support\Signal;
use Cbox\Telemetry\Support\TelemetryBatch;
use Cbox\Telemetry\Support\TraceParent;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\SpanStatus;
use Cbox\Telemetry\Tracing\Tracer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

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

    private ?Closure $requestNameResolver = null;

    private ?Closure $routeResolver = null;

    private ?Closure $requestEnricher = null;

    private ?Closure $cacheKeyClassifier = null;

    private ?Closure $sessionResolver = null;

    private ?Closure $clientGeoResolver = null;

    /** @var array{id: string, type: string, guard: string|null}|null */
    private ?array $rememberedUser = null;

    /**
     * @param  array<string, scalar>  $resource
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly Registry $registry,
        private readonly Tracer $tracer,
        private readonly array $resource = [],
        private readonly int $maxBufferedEvents = 5000,
        private readonly bool $tailDetails = false,
        private readonly float $slowRequestMs = 1000.0,
        private readonly float $slowSpanMs = 100.0,
        private readonly ?Redactor $redactor = null,
        private readonly bool $selfMetrics = true,
    ) {
        // A buffer-cap flush means the trace is pathological — keep every
        // detail for it.
        $this->tracer->onBufferFull(fn () => $this->flush(forceDetails: true));
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
     *
     * @return ($callback is null ? Gauge : ObservableGauge)
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
        $this->rememberedUser = null;
    }

    /**
     * Remember the identity that authenticated during THIS request — the
     * Login event fires on the login POST itself, before the request
     * span's user attribution runs, and Logout empties the guard before
     * terminate. Without this, exactly those two request types would be
     * anonymous. (A Nightwatch-agent lesson.)
     *
     * @param  array{id: string, type: string, guard: string|null}  $identity
     */
    public function rememberAuthenticatedUser(array $identity): void
    {
        $this->rememberedUser = $identity;
    }

    /**
     * @internal used by the request middleware as a fallback
     *
     * @return array{id: string, type: string, guard: string|null}|null
     */
    public function rememberedAuthenticatedUser(): ?array
    {
        return $this->rememberedUser;
    }

    /**
     * Publish the trace id to the wider ecosystem so error trackers and
     * logs can correlate back to the trace:
     *
     * - Laravel's Context facade (`trace_id`) — picked up automatically
     *   by sentry-laravel (>= 4.x), Flare and every log channel.
     * - An explicit Sentry scope tag when the SDK is installed, so the
     *   issue page shows trace_id even on older SDK versions.
     *
     * Called by the request/job/task instrumentation at trace start.
     */
    public function publishTraceContext(): void
    {
        if (! $this->enabled || ! config('telemetry.traces.share_context', true)) {
            return;
        }

        $traceId = $this->tracer->traceId();

        if ($traceId === null) {
            return;
        }

        FailSafe::guard(function () use ($traceId) {
            if (class_exists(Context::class)) {
                Context::add('trace_id', $traceId);
            }

            if (function_exists('\Sentry\configureScope')) {
                \Sentry\configureScope(function ($scope) use ($traceId): void {
                    $scope->setTag('trace_id', $traceId);
                });
            }
        });
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
     * Name request root spans yourself — essential behind catch-all
     * routes (Statamic, wildcard APIs) where the route pattern names
     * every request identically. Return null to keep the default
     * "METHOD /route/{pattern}" name. Keep names BOUNDED — never ids:
     *
     *     Telemetry::nameRequestsUsing(function ($request, $response) {
     *         $entry = $request->attributes->get('statamic.entry');
     *
     *         return $entry ? 'GET entry:'.$entry->collectionHandle() : null;
     *     });
     *
     * A span renamed explicitly during the request (updateName) always
     * wins over both this resolver and the default.
     *
     * @param  (Closure(mixed, mixed): ?string)|null  $resolver
     */
    public function nameRequestsUsing(?Closure $resolver): void
    {
        $this->requestNameResolver = $resolver;
    }

    /**
     * @internal used by the request middleware
     */
    public function resolveRequestName(mixed $request, mixed $response): ?string
    {
        if ($this->requestNameResolver === null) {
            return null;
        }

        $name = FailSafe::guard(fn () => ($this->requestNameResolver)($request, $response));

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Supply the logical route identity for catch-all frameworks — a
     * CMS's single "/{segments?}" names every page the same. The return
     * value replaces the `http.route` span attribute AND metric label, so
     * the whole ecosystem — dashboards, route tables, TraceQL — groups by
     * the logical route, not the useless catch-all template. The literal
     * Laravel route template is preserved as the `http.route.template`
     * attribute.
     *
     * MUST be bounded: `http.route` is a metric label, so return a value
     * from a fixed, small set (a content type, a collection), never an id
     * or slug. Return null to keep the literal route template. This is the
     * route counterpart to nameRequestsUsing (which shapes the span name);
     * an instrumentation for a catch-all framework typically sets both.
     *
     *     Telemetry::resolveRouteUsing(function ($request, $response) {
     *         $entry = $request->attributes->get('statamic.entry');
     *
     *         return $entry ? 'entry:'.$entry->collectionHandle() : null;
     *     });
     *
     * @param  (Closure(mixed, mixed): ?string)|null  $resolver
     */
    public function resolveRouteUsing(?Closure $resolver): void
    {
        $this->routeResolver = $resolver;
    }

    /**
     * @internal used by the request middleware
     */
    public function resolveRoute(mixed $request, mixed $response): ?string
    {
        if ($this->routeResolver === null) {
            return null;
        }

        $route = FailSafe::guard(fn () => ($this->routeResolver)($request, $response));

        return is_string($route) && $route !== '' ? $route : null;
    }

    /**
     * Add attributes to the request root span at terminate — the
     * response is final, so status-dependent enrichment works:
     *
     *     Telemetry::enrichRequestsUsing(fn ($request, $response) => [
     *         'statamic.static_cache' => $response->headers->get('X-Statamic-Cache', 'miss'),
     *     ]);
     *
     * Runs before the tail-detail decision and the redaction engine.
     *
     * @param  (Closure(mixed, mixed): array<string, scalar|null>)|null  $resolver
     */
    public function enrichRequestsUsing(?Closure $resolver): void
    {
        $this->requestEnricher = $resolver;
    }

    /**
     * @internal used by the request middleware
     *
     * @return array<string, scalar|null>
     */
    public function resolveRequestEnrichment(mixed $request, mixed $response): array
    {
        if ($this->requestEnricher === null) {
            return [];
        }

        return FailSafe::guard(fn (): array => ($this->requestEnricher)($request, $response)) ?? [];
    }

    /**
     * Classify cache keys into bounded groups — or drop them. With a
     * classifier registered, every recorded cache operation carries the
     * returned group (counter label `key_group`, span attribute
     * `cache.key.group`); returning null drops the operation entirely.
     * This is how a Stache-heavy CMS turns thousands of raw keys into
     * "stache.index" instead of flooding the timeline:
     *
     *     Telemetry::classifyCacheKeysUsing(function (string $store, string $key) {
     *         return str_starts_with($key, 'stache::') ? 'stache' : 'app';
     *     });
     *
     * @param  (Closure(string, string): ?string)|null  $classifier
     */
    public function classifyCacheKeysUsing(?Closure $classifier): void
    {
        $this->cacheKeyClassifier = $classifier;
    }

    /**
     * @internal used by the cache instrumentation
     */
    public function hasCacheKeyClassifier(): bool
    {
        return $this->cacheKeyClassifier !== null;
    }

    /**
     * @internal used by the cache instrumentation
     */
    public function classifyCacheKey(string $store, string $key): ?string
    {
        if ($this->cacheKeyClassifier === null) {
            return null;
        }

        $group = FailSafe::guard(fn () => ($this->cacheKeyClassifier)($store, $key));

        return is_string($group) ? $group : null;
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
     * default — only enduser.id/type/guard ship out of the box). The
     * resolver receives the user AND the guard that authenticated, so
     * multi-guard apps can attribute per model:
     *
     *     Telemetry::resolveUserUsing(fn ($user, ?string $guard) => [
     *         'enduser.plan' => $user->plan,
     *     ]);
     *
     * @param  (Closure(mixed, ?string): array<string, scalar|null>)|null  $resolver
     */
    public function resolveUserUsing(?Closure $resolver): void
    {
        $this->userAttributeResolver = $resolver;
    }

    /**
     * Register a custom redaction hook, run after the built-in key and
     * pattern strategies on every string attribute at flush time:
     *
     *     Telemetry::redactUsing(function (string $key, string $value) {
     *         return str_contains($key, 'cpr') ? '[REDACTED]' : null;
     *     });
     *
     * Return a replacement string, or null to keep the value.
     *
     * @param  (Closure(string, string): ?string)|null  $hook
     */
    public function redactUsing(?Closure $hook): void
    {
        $this->redactor?->redactUsing($hook);
    }

    /**
     * @internal used by the request middleware
     *
     * @return array<string, scalar|null>
     */
    public function resolveUserAttributes(mixed $user, ?string $guard = null): array
    {
        if ($this->userAttributeResolver === null) {
            return [];
        }

        return FailSafe::guard(fn (): array => ($this->userAttributeResolver)($user, $guard)) ?? [];
    }

    /**
     * Override how the analytics `session.id` (the shared visit key across
     * browser + server) is derived from the request. The built-in default
     * is a cookieless, daily-rotating salted hash; a hook lets you source it
     * from Cloudflare (e.g. `CF-Ray`), a first-party cookie, or your own
     * logic:
     *
     *     Telemetry::resolveSessionUsing(fn ($request) =>
     *         $request->header('CF-Ray') ?: $request->cookie('visit'));
     *
     * Return null to fall back to the cookieless default.
     *
     * @param  (Closure(Request): ?string)|null  $resolver
     */
    public function resolveSessionUsing(?Closure $resolver): void
    {
        $this->sessionResolver = $resolver;
    }

    /**
     * @internal used by the request middleware / browser snippet
     */
    public function resolveSessionId(Request $request): ?string
    {
        if ($this->sessionResolver === null) {
            return null;
        }

        $id = FailSafe::guard(fn (): ?string => ($this->sessionResolver)($request));

        return ($id === null || $id === '') ? null : $id;
    }

    /**
     * Provide `client.geo.*` for the request span and analytics events —
     * e.g. from Cloudflare's edge headers, so no geo database is needed:
     *
     *     Telemetry::resolveClientGeoUsing(fn ($request) => array_filter([
     *         'client.geo.country'      => $request->header('CF-IPCountry'),
     *         'client.geo.region'       => $request->header('CF-Region'),
     *         'client.address'          => $request->header('CF-Connecting-IP'),
     *     ]));
     *
     * @param  (Closure(Request): array<string, scalar|null>)|null  $resolver
     */
    public function resolveClientGeoUsing(?Closure $resolver): void
    {
        $this->clientGeoResolver = $resolver;
    }

    /**
     * @internal used by the request middleware
     *
     * @return array<string, scalar|null>
     */
    public function resolveClientGeo(Request $request): array
    {
        if ($this->clientGeoResolver === null) {
            return [];
        }

        return FailSafe::guard(fn (): array => ($this->clientGeoResolver)($request)) ?? [];
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
    public function flush(bool $forceDetails = false): void
    {
        // Buffered stores push their aggregated metric writes at the same
        // points spans flush — request terminate, after each queue job —
        // even when no spans or events are pending.
        $store = $this->registry->store();

        if ($store instanceof BufferedMetricStore) {
            FailSafe::guard(fn () => $store->flushBuffer());
        }

        $spans = $this->tracer->drain();

        if (! $forceDetails) {
            $spans = $this->applyTailDetailPolicy($spans);
        }

        $events = $this->events;
        $this->events = [];

        // The redaction engine — the last hands on every attribute value
        // before an exporter sees it.
        if ($this->redactor !== null) {
            $spans = FailSafe::guard(fn (): array => $this->redactor->spans($spans)) ?? $spans;
            $events = FailSafe::guard(fn (): array => $this->redactor->events($events)) ?? $events;
        }

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
     * Export externally-produced spans (browser RUM) directly, under their
     * own trace/span ids — so they join the SAME trace as the backend when
     * the browser propagated its traceparent. Redacted like any span.
     *
     * @param  list<Span>  $spans
     */
    public function ingestSpans(array $spans): void
    {
        if (! $this->enabled || $spans === []) {
            return;
        }

        if ($this->redactor !== null) {
            $spans = FailSafe::guard(fn (): array => $this->redactor->spans($spans)) ?? $spans;
        }

        $this->flushing = true;

        try {
            $this->export(new TelemetryBatch(resource: $this->resource, spans: $spans), Signal::Traces);
        } finally {
            $this->flushing = false;
        }
    }

    /**
     * Export externally-produced events (browser analytics: SPA page views,
     * engagement, custom track() calls) directly as OTLP log records —
     * unsampled, so a page view is never undercounted. Redacted like any
     * event.
     *
     * @param  list<TelemetryEvent>  $events
     */
    public function ingestEvents(array $events): void
    {
        if (! $this->enabled || $events === []) {
            return;
        }

        if ($this->redactor !== null) {
            $events = FailSafe::guard(fn (): array => $this->redactor->events($events)) ?? $events;
        }

        $this->flushing = true;

        try {
            $this->export(new TelemetryBatch(resource: $this->resource, events: $events), Signal::Events);
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

    /**
     * Tail detail retention: MANY details for traces that failed or were
     * slow, a lean skeleton (+ the always-flowing aggregates) when all
     * was well. Possible because the whole trace sits in memory until
     * terminate.
     *
     * A trace keeps its detail spans (cache ops, queries) when it has an
     * error span, any span at/over slow_request_ms, or a DETAIL span
     * at/over slow_span_ms (a slow query makes its trace interesting).
     *
     * @param  list<Span>  $spans
     * @return list<Span>
     */
    private function applyTailDetailPolicy(array $spans): array
    {
        if (! $this->tailDetails || $spans === []) {
            return $spans;
        }

        /** @var array<string, bool> $interesting */
        $interesting = [];

        foreach ($spans as $span) {
            if (
                $span->status() === SpanStatus::Error
                || $span->durationMs() >= $this->slowRequestMs
                || ($span->isDetail() && $span->durationMs() >= $this->slowSpanMs)
            ) {
                $interesting[$span->traceId] = true;
            }
        }

        return array_values(array_filter(
            $spans,
            fn (Span $span): bool => ! $span->isDetail() || ($interesting[$span->traceId] ?? false),
        ));
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
                $startedAt = microtime(true);
                $result = FailSafe::guard(fn () => $exporter->export($narrowed));
                $this->recordExportMetrics($exporter->name(), $signals, $startedAt, $result);
            }
        }
    }

    /**
     * Self-observability: how the exporters themselves are doing. These
     * are plain store writes (no export inline), so there is no feedback
     * loop — they ship on the next metrics flush like any counter.
     *
     * @param  array<Signal>  $signals
     */
    private function recordExportMetrics(string $exporter, array $signals, float $startedAt, ?ExportResult $result): void
    {
        if (! $this->selfMetrics) {
            return;
        }

        FailSafe::guard(function () use ($exporter, $signals, $startedAt, $result) {
            $kind = in_array(Signal::Metrics, $signals, true) ? 'metrics' : 'traces_logs';
            $labels = ['exporter' => $exporter, 'signal' => $kind];

            $outcome = match (true) {
                $result === null => 'error',           // exporter threw
                $result->rejected > 0 => 'partial',
                $result->success => 'ok',
                $result->retryable => 'retryable',
                default => 'failed',
            };

            $this->registry->histogram('telemetry.export.duration', description: 'Telemetry export duration', unit: 'ms')
                ->record((microtime(true) - $startedAt) * 1000, $labels);

            $this->registry->counter('telemetry.export.count', 'Telemetry export attempts by outcome')
                ->inc(1, $labels + ['outcome' => $outcome]);

            if ($result !== null && $result->rejected > 0) {
                $this->registry->counter('telemetry.export.rejected', 'Data points the backend rejected (OTLP partial success)')
                    ->inc($result->rejected, $labels);
            }
        });
    }
}
