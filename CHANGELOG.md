# Changelog

All notable changes to `cboxdk/laravel-telemetry` are documented here.

## Unreleased

Initial release.

### Performance

- Write buffering (`buffer_writes`, default on): metric writes aggregate
  in memory and flush once at request/job terminate — repeated increments
  cost one store command, histogram observations flush as pre-aggregated
  buckets. `MetricStore` gained `mergeHistogram()` for this.

### Observability UX

- Request spans carry `enduser.id` (authenticated user id, opt-out via
  `instrument.user`) for per-user trace filtering.
- Queue metric label renamed `job` -> `job.name` (`job_name` in
  Prometheus) — a bare `job` label collides with Prometheus' reserved
  scrape-job label and was silently overwritten by collectors.

### Hardening (post-review)

- Redis store: steady-state writes are now a single atomic command
  (Redis Cluster-safe, ~5x fewer round trips); metadata refreshes per
  deploy; `__since` field feeds OTLP cumulative start timestamps.
- Event buffer capped (`events.max_buffer`) — long-running workers can't
  grow memory unbounded.
- Registry rejects mixing push and observable gauges under one name;
  the Prometheus renderer additionally deduplicates same-name families
  so a collision can never fail the whole scrape.
- Queue instrumentation covers released-for-retry attempts
  (`queue.jobs.released`) and keeps job spans on a stack so nested sync
  dispatches can't leak the outer span.
- OTLP: per-process circuit breaker after retryable failures (honours
  Retry-After), gzip request compression, explicit TLS verification,
  NAN/INF-safe serialization, `startTimeUnixNano` on cumulative points.
- Query spans skip unsampled traces and support a
  `queries_min_duration` noise floor.
- `traces.trust_incoming_sampling` — keep trace-id correlation on public
  edges while deciding sampling locally.
- New `telemetry:doctor` command: store round trip, exporter
  reachability, config warnings (flags an unprotected scrape endpoint).

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
  `llms.txt` documentation index, an `AGENTS.md`/`CLAUDE.md` agent guide
  for contributors, and copy-paste **Agent prompt** blocks in the docs
  (install, instrument-my-app, log channel, package provider, Grafana).
