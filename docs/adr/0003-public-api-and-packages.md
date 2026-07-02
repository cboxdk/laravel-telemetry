# ADR-0003: Public API shape and package layout

- Status: accepted
- Date: 2026-07-02

## Public API

Two layers, one facade:

```php
// Low-level, generic (core)
Telemetry::counter('orders.created')->inc();          // push → MetricStore
Telemetry::gauge('queue.depth', fn () => ...);         // pull → callback, scrape-time
Telemetry::histogram('checkout.duration')->record($ms);
Telemetry::span('import.customers', fn () => ...);     // in-memory, flushed at shutdown
Telemetry::event('autoscale.decision', [...]);

// Laravel-semantic (opinionated, built on the layer above)
Telemetry::trackRequest($request);
Telemetry::trackJob($job);
Telemetry::trackQuery($query);
Telemetry::trackCommand($command);
```

Push and pull instruments are **distinct API shapes** (mutation object vs.
callback registration) — they are different mechanisms and must not look
interchangeable. (spatie/laravel-prometheus's `inc()` that silently buffers
a scrape-time re-applied increment is the confusion we're avoiding.)

DX details adopted from prior art:

- **Spans are objects, not name-keyed** — spatie's `startedSpans[$name]`
  dict makes two concurrent same-named spans collide. `span()` returns the
  span; the closure form is the primary API.
- **Pull gauges may return multi-series**: a single callback can return
  `[[value, [labelValues]], ...]` (spatie/laravel-prometheus pattern).
- **Human-label slugging** (`'User count'` → `user_count`) as sugar, with
  OTel names canonical underneath.
- **Full W3C traceparent propagation** — queue payload and outbound HTTP
  carry trace ID *and* parent span ID, so job/downstream spans are children,
  not detached roots. (spatie propagates only the trace ID; parenting breaks.)
- **Multiple named scrape endpoints**, each with its own metric set and
  middleware stack (public vs. sensitive).

## Provider contract (decoupling)

```php
interface TelemetryProvider
{
    public function name(): string;                       // 'cbox.queue-metrics'
    public function register(TelemetryRegistry $registry): void;
}
```

Sibling packages self-register, guarded:

```php
if (class_exists(\Cbox\Telemetry\Telemetry::class)) {
    Telemetry::provider(new QueueMetricsProvider());
}
```

The telemetry package never references sibling packages. Provider
registration is lazy: providers are resolved only when an exporter is active.

## Exporter contract

```php
interface Exporter
{
    public function supports(): SignalSet;                // traces|metrics|logs|events
    public function export(TelemetryBatch $batch): ExportResult;
}
```

`ExportResult` carries success/retryable-failure/permanent-failure and a
partial-rejection count — retry policy is decided by the pipeline, not the
exporter.

## Testing

`Telemetry::fake()` swaps in array store + null exporters and exposes
assertions: `assertCounterIncremented()`, `assertGaugeRegistered()`,
`assertSpanRecorded()`, `assertEventEmitted()`. This is a first-class
feature so sibling packages can test their providers.

## Zero-cost when disabled, silent-fail when enabled

With no exporter configured, instruments resolve to no-op objects,
providers are never registered, **and no event listeners are attached**
(Pulse/Telescope still register listeners when enabled-but-sampled; we skip
registration entirely). A disabled install must add no measurable
per-request overhead.

When enabled, telemetry must never throw into the app: every capture path
is wrapped in `rescue()`-style handling with a
`Telemetry::handleExceptionsUsing()` hook (Pulse's model).

## Sampling

Per-provider capture-time sample rate (0–1), Pulse-style, plus an optional
flush-time filter closure for spans (Telescope's `filter()` precedent).

## Package layout

Single repo, **one package for v1**: `cbox/laravel-telemetry`
(core + Laravel integration + redis/apcu/array stores + prometheus &
otlp-http exporters + null/fake).

Split into `telemetry-core` / exporter packages later **only if** a
non-Laravel consumer or a heavy exporter dependency (e.g. gRPC) forces it.
Five packages on day one is structure without users.
