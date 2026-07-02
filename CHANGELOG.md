# Changelog

All notable changes to `cboxdk/laravel-telemetry` are documented here.

## Unreleased

Initial release.

- Counters, push/observable gauges and histograms over a shared metric
  store (Redis, APCu, array drivers).
- Tracing with W3C trace context: automatic request, queue job, DB query
  and Artisan command spans; full traceparent propagation into queued jobs.
- Structured events exported as trace-correlated OTLP log records.
- Prometheus scrape endpoints (multiple, named, filterable, IP-guarded).
- Direct OTLP/HTTP JSON export (traces, metrics, logs) — no SDK, no
  collector required.
- `telemetry:flush` command for scheduled OTLP metric export.
- `TelemetryProvider` contract + `Telemetry::contributes()` for decoupled
  package telemetry; built-in `cboxdk/system-metrics` provider.
- `Telemetry::fake()` with metric, span and event assertions (positive and
  negative).
- Push gauges adjust atomically with `increment()`/`decrement()` for
  up-and-down values (in-flight jobs, active connections).
- `Http::withTraceparent()` macro for opt-in outbound trace propagation.
- `telemetry` log channel: Laravel logs exported as trace-correlated OTLP
  log records with Monolog severity mapping and feedback-loop protection.
- `php artisan about` section showing store, exporters, endpoints and
  sample rate.
- AI surface: Laravel Boost package guidelines (`.ai/guidelines/`),
  `llms.txt` documentation index, and an `AGENTS.md`/`CLAUDE.md` agent
  guide for contributors.
