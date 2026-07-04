---
title: Telemetry for Laravel
description: Documentation for Cbox's collector-free telemetry package for Laravel
weight: 1
---

# Telemetry for Laravel

`cboxdk/laravel-telemetry` is collector-free telemetry for Laravel: Prometheus
metrics, OTLP traces and structured events from a plain Composer package.

No C extension, no OTel SDK, no protobuf, no sidecar. Metrics live in a shared
store (Redis by default) so they are correct under PHP's shared-nothing
process model — across web workers, queue workers and nodes. Traces are real
OTLP over HTTP JSON, exported directly to any OpenTelemetry backend.

```php
Telemetry::counter('orders.created')->inc();
Telemetry::gauge('queue.depth', fn () => Queue::size());
Telemetry::histogram('checkout.duration', unit: 'ms')->record($ms);

Telemetry::span('import.customers', function () {
    // traced work
});

Telemetry::event('autoscale.decision', ['workers' => 5]);

Log::info('Import done', ['rows' => 5021]); // → trace-correlated OTLP log
```

## Why this package

- **Metrics that work under FPM.** The official OTel PHP SDK aggregates
  metrics in-process, which breaks under shared-nothing PHP. This package
  aggregates in Redis (or APCu) — every process writes to the same series.
- **No infrastructure required.** Prometheus scrapes a route; OTLP posts
  directly over HTTP. An OTel collector is supported but never required.
- **Packages publish telemetry without coupling.** Sibling packages
  self-register providers when telemetry is installed — telemetry never
  knows about them.
- **First-class testing.** `Telemetry::fake()` gives in-memory assertions
  for counters, gauges, histograms, spans and events.
- **Zero cost when disabled.** No listeners, no providers, no-op
  instruments.

## The cboxdk observability suite

- `cboxdk/laravel-telemetry` — this package: instruments, traces, export.
- `cboxdk/system-metrics` — host CPU/memory/load read straight from the OS;
  auto-published as `system.*` metrics when installed.
- `cboxdk/laravel-queue-metrics`, `cboxdk/laravel-queue-autoscale`,
  `cboxdk/laravel-health` — publish their own telemetry through providers.

## Sections

- Getting started: install, first metrics and spans, testing with the fake,
  and the one-page API reference.
- Core concepts: architecture, metrics, traces, events, logs, naming.
- Cookbook: local LGTM stack (dev/test/CI), multi-tenant SaaS, queue monitoring, external services.
- Configuration: every config key explained.
- Extension points: telemetry providers and custom exporters.
- Production: Prometheus setup, OTLP setup, the recommended Grafana stack,
  performance, security, browser/RUM tracing, and the error-tracking/support flow
  (trace ids in Sentry, on error pages and in the response header).
