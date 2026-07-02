---
title: Configuration reference
description: Every config key in config/telemetry.php
weight: 1
---

# Configuration reference

Publish with `php artisan vendor:publish --tag=telemetry-config`.

## Master switch

| Key | Env | Default |
|---|---|---|
| `enabled` | `TELEMETRY_ENABLED` | `true` |

Disabled: no-op instruments, no listeners, no routes, no providers.

## Service resource

Attached to every exported signal (OTel resource conventions).

| Key | Env | Default |
|---|---|---|
| `service.name` | `TELEMETRY_SERVICE_NAME` | `APP_NAME` |
| `service.namespace` | `TELEMETRY_SERVICE_NAMESPACE` | — |
| `service.version` | `TELEMETRY_SERVICE_VERSION` | — |
| `service.environment` | `TELEMETRY_ENVIRONMENT` | `APP_ENV` |
| `service.deployment` | `TELEMETRY_DEPLOYMENT` | — (git sha/release tag → `deployment.id` resource attribute) |

## Metric store

| Key | Env | Default |
|---|---|---|
| `store` | `TELEMETRY_STORE` | `redis` |
| `buffer_writes` | `TELEMETRY_BUFFER_WRITES` | `true` — aggregate writes in memory, flush at terminate |
| `stores.redis.connection` | `TELEMETRY_REDIS_CONNECTION` | `default` |
| `stores.redis.prefix` | `TELEMETRY_REDIS_PREFIX` | `telemetry` |
| `stores.apcu.prefix` | `TELEMETRY_APCU_PREFIX` | `telemetry` |

## Exporters

| Key | Env | Default |
|---|---|---|
| `exporters` | `TELEMETRY_EXPORTERS` (comma-separated) | `[]` |

Valid entries: `otlp`, `null`, or a fully-qualified class name implementing
`Cbox\Telemetry\Contracts\Exporter` (resolved from the container).

### OTLP

| Key | Env | Default |
|---|---|---|
| `otlp.endpoint` | `OTEL_EXPORTER_OTLP_ENDPOINT` | `http://localhost:4318` |
| `otlp.headers` | — | `[]` |
| `otlp.timeout` | `TELEMETRY_OTLP_TIMEOUT` | `3.0` s |
| `otlp.connect_timeout` | `TELEMETRY_OTLP_CONNECT_TIMEOUT` | `1.0` s |
| `otlp.compression` | `TELEMETRY_OTLP_COMPRESSION` | `true` (gzip bodies > 1 KB) |

After a retryable transport failure (429/5xx, network), an in-process
circuit breaker skips exports for 30 s (or the server's `Retry-After`).

### Prometheus

| Key | Env | Default |
|---|---|---|
| `prometheus.enabled` | `TELEMETRY_PROMETHEUS_ENABLED` | `true` |
| `prometheus.endpoints.*.path` | `TELEMETRY_PROMETHEUS_PATH` | `telemetry/metrics` |
| `prometheus.endpoints.*.middleware` | — | `[AllowIps::class]` |
| `prometheus.endpoints.*.only` | — | `null` (all metrics) |
| `prometheus.allowed_ips` | `TELEMETRY_ALLOWED_IPS` (comma-separated, CIDR ok) | `[]` (allow all) |

Multiple named endpoints are supported — e.g. an `internal` endpoint with
everything and a `public` endpoint filtered to a prefix list:

```php
'endpoints' => [
    'internal' => ['path' => 'internal/metrics', 'middleware' => [AllowIps::class]],
    'public' => ['path' => 'metrics', 'middleware' => ['auth:metrics'], 'only' => ['http', 'queue']],
],
```

## Traces

| Key | Env | Default |
|---|---|---|
| `traces.sample_rate` | `TELEMETRY_TRACES_SAMPLE_RATE` | `1.0` |
| `traces.max_buffer` | `TELEMETRY_TRACES_MAX_BUFFER` | `5000` |
| `traces.continue_incoming` | `TELEMETRY_TRACES_CONTINUE_INCOMING` | `true` |
| `traces.trust_incoming_sampling` | `TELEMETRY_TRACES_TRUST_INCOMING_SAMPLING` | `true` — disable on public edges so clients can't force sampling |
| `traces.always_sample_errors` | `TELEMETRY_TRACES_ALWAYS_SAMPLE_ERRORS` | `true` — error spans export even from unsampled traces |
| `traces.share_context` | `TELEMETRY_TRACES_SHARE_CONTEXT` | `true` — publishes `trace_id` into Laravel `Context` (Sentry/Flare/logs pick it up) + a Sentry scope tag |
| `traces.response_header` | `TELEMETRY_TRACES_RESPONSE_HEADER` | `X-Trace-Id` — the support reference id on every response; `null` disables |
| `traces.details.mode` | `TELEMETRY_TRACE_DETAILS` | `always` — `tail` keeps cache/query detail spans only for failing or slow traces |
| `traces.details.slow_request_ms` | `TELEMETRY_SLOW_REQUEST_MS` | `1000` |
| `traces.details.slow_span_ms` | `TELEMETRY_SLOW_SPAN_MS` | `100` |

## Redaction engine

| Key | Env | Default |
|---|---|---|
| `redaction.enabled` | `TELEMETRY_REDACTION` | `true` |
| `redaction.keys` | — | `Redactor::defaultKeys()` — password, secret, token, api_key, authorization, cvv, ssn, … (whole key segments) |
| `redaction.patterns` | — | `Redactor::defaultPatterns()` — JWTs, Bearer/Basic credentials, url userinfo (regex ⇒ replacement) |
| `redaction.replacement` | — | `[REDACTED]` |

Applied to span attributes, span events and telemetry events at flush.
Custom last-pass hook: `Telemetry::redactUsing(fn ($key, $value) => ...)`.

## Events

| Key | Env | Default |
|---|---|---|
| `events.max_buffer` | `TELEMETRY_EVENTS_MAX_BUFFER` | `5000` (force-flush above) |

## Histograms

| Key | Default |
|---|---|
| `default_buckets` | `[1, 5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000]` (ms) |

## Automatic instrumentation

| Key | Env | Default |
|---|---|---|
| `instrument.requests` | `TELEMETRY_INSTRUMENT_REQUESTS` | `true` |
| `instrument.host_label` | `TELEMETRY_INSTRUMENT_HOST_LABEL` | `true` — `server.address` label on `http.server.*` metrics; routes with a domain pattern report the pattern (`{tenant}.app.example`), keeping wildcard cardinality bounded |
| `instrument.request_headers` | — | `accept, accept-language, content-type, origin, referer, x-forwarded-for, x-requested-with` — span attrs `http.request.header.*`; credentials/session headers are denylisted and never captured |
| `instrument.response_headers` | — | `content-type, cache-control` — span attrs `http.response.header.*` |
| `instrument.jobs` | `TELEMETRY_INSTRUMENT_JOBS` | `true` |
| `instrument.queries` | `TELEMETRY_INSTRUMENT_QUERIES` | `true` |
| `instrument.queries_min_duration` | `TELEMETRY_QUERIES_MIN_DURATION` | `0` ms (record everything; raise as a noise floor) |
| `instrument.commands` | `TELEMETRY_INSTRUMENT_COMMANDS` | `false` |
| `instrument.user` | `TELEMETRY_INSTRUMENT_USER` | `true` — tag request spans with `enduser.id` + `enduser.type` (model) + `enduser.guard` (multi-guard safe; never PII) |
| `instrument.resources` | `TELEMETRY_INSTRUMENT_RESOURCES` | `true` — peak memory + CPU per request/job/task; with cboxdk/system-metrics also real RSS + CPU utilization |
| `instrument.scheduled_tasks` | `TELEMETRY_INSTRUMENT_SCHEDULED_TASKS` | `true` — task spans + processed/failed/skipped counters |
| `instrument.cache` | `TELEMETRY_INSTRUMENT_CACHE` | `false` — cache.operations counters (hit/miss/write/forget) |
| `instrument.cache_spans` | `TELEMETRY_INSTRUMENT_CACHE_SPANS` | `false` — timeline spans per cache op with key/store/duration |
| `instrument.mail` | `TELEMETRY_INSTRUMENT_MAIL` | `true` — mail.send spans + counter |
| `instrument.notifications` | `TELEMETRY_INSTRUMENT_NOTIFICATIONS` | `true` — notification.send spans + counter |
| `instrument.http_client` | `TELEMETRY_INSTRUMENT_HTTP_CLIENT` | `true` — outgoing Http-client spans + duration by host/method/status |
| `instrument.exceptions` | `TELEMETRY_INSTRUMENT_EXCEPTIONS` | `true` — exceptions.reported counter incl. handled report()s |
| `queue.propagate` | `TELEMETRY_QUEUE_PROPAGATE` | `true` |

## Host & process monitor

| Key | Env | Default |
|---|---|---|
| `monitor.interval` | `TELEMETRY_MONITOR_INTERVAL` | `15` s (daemon mode) |
| `monitor.processes` | — | `[]` — name => pgrep pattern (e.g. `'reverb' => 'reverb:start'`) |

`telemetry:monitor --once` from the scheduler (cron mode) or without
`--once` under supervisor (daemon mode). Queue workers additionally
self-report `worker.memory.{php,rss}{queue,pid} (By)` after every job —
no monitor required for worker leak tracking.

## Built-in providers

| Key | Env | Default |
|---|---|---|
| `providers.system.enabled` | `TELEMETRY_SYSTEM_METRICS` | `true` |
| `providers.system.cpu_interval` | `TELEMETRY_SYSTEM_CPU_INTERVAL` | `0.1` s (`0` disables CPU utilization) |

The system provider only activates when `cboxdk/system-metrics` is
installed.
