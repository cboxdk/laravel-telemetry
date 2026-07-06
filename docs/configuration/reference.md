---
title: Configuration reference
description: Every config key in config/telemetry.php
weight: 1
---

# Configuration reference

Publish with `php artisan vendor:publish --tag=telemetry-config`.

**Env-var convention:** every variable is prefixed `TELEMETRY_` and mirrors
its config path (`otlp.spool.key` → `TELEMETRY_OTLP_SPOOL_KEY`). The
package additionally honors the OpenTelemetry-standard variables
(`OTEL_EXPORTER_OTLP_ENDPOINT`, `OTEL_EXPORTER_OTLP_HEADERS`,
`OTEL_SERVICE_NAME`, `OTEL_RESOURCE_ATTRIBUTES`) as fallbacks for interop —
`TELEMETRY_*` wins when both are set.

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
| `service.environment` | `TELEMETRY_SERVICE_ENVIRONMENT` | `APP_ENV` |
| `service.deployment` | `TELEMETRY_SERVICE_DEPLOYMENT` | auto — explicit value wins; otherwise the current git sha is detected from `.git/HEAD` (no exec). Becomes `deployment.id` on every signal |
| `resource_detection` | `TELEMETRY_RESOURCE_DETECTION` | `true` — auto-detect container/k8s/cloud attributes (`container.id`, `k8s.pod.name`, `k8s.namespace.name`, `cloud.region`, …) from cgroup facts, downward-API env vars and `OTEL_RESOURCE_ATTRIBUTES`. Config `service.*` keys always win |
| `self_metrics` | `TELEMETRY_SELF_METRICS` | `true` — emit the package's own health as metrics (`telemetry.export.*`, `telemetry.spool.depth`) |

### Container / Kubernetes / cloud detection

With `resource_detection` on, every signal carries where it ran:

- **From cgroups** (via `cboxdk/system-metrics`): `container.id`,
  `container.runtime`.
- **From env vars** (the common downward-API injections):
  `k8s.pod.name` (`K8S_POD_NAME`/`POD_NAME`, or the pod's `HOSTNAME`),
  `k8s.namespace.name`, `k8s.node.name`, `cloud.region`
  (`AWS_REGION`/`GOOGLE_CLOUD_REGION`/…), and more.
- **From `OTEL_RESOURCE_ATTRIBUTES`** — the OpenTelemetry standard
  (`key1=val1,key2=val2`, percent-decoded). Operator-explicit, overrides
  the env-var conventions. This is the cleanest way to inject arbitrary
  attributes; k8s operators set it automatically.
- **From AWS Lambda's own runtime env vars** (Vapor included — it
  deploys on Bref's PHP-FPM Lambda layer, so the same vars are always
  present): `cloud.provider=aws`, `cloud.platform=aws_lambda`,
  `faas.name`/`.version`/`.instance`/`.max_memory`, and a per-invocation
  `faas.coldstart` (true only on the first request served by a given
  execution environment — the PHP-FPM worker is reused across warm
  invocations, so this is re-evaluated on every call, unlike the rest
  of the detected resource which is memoized for the process). Vapor
  itself is flagged with `vapor.detected` (from `VAPOR_SSM_PATH`) —
  there's no standardized OTel value for "which PaaS wraps this Lambda".

Precedence: the package's own `service.*` config is authoritative;
`OTEL_RESOURCE_ATTRIBUTES` beats the env-var conventions; both fill in
around the config. Filter any of these in Tempo:
`{ resource.k8s.namespace.name = "production" }`,
`{ resource.faas.name = "my-app-production-http" }`.

### Self-observability metrics

With `self_metrics` on, the package reports on itself (bounded labels):

| Metric | Type | Labels | Meaning |
|---|---|---|---|
| `telemetry.export.duration` | histogram (ms) | exporter, signal | how long exports take |
| `telemetry.export.count` | counter | exporter, signal, outcome | export attempts by outcome (ok/partial/retryable/failed/error) |
| `telemetry.export.rejected` | counter | exporter, signal | data points the backend rejected (OTLP partial success) |
| `telemetry.export.circuit_open` | gauge (0/1) | — | OTLP breaker open (only when OTLP is a configured exporter) |
| `telemetry.spool.depth` | gauge | — | pending OTLP spool payloads (only when the spool is enabled) |

The bundled **System** dashboard renders these under a "Telemetry
health" row — alert on a sustained open circuit or a climbing spool.

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
| `otlp.endpoint` | `TELEMETRY_OTLP_ENDPOINT` | `http://localhost:4318` — also honors the OTel-standard `OTEL_EXPORTER_OTLP_ENDPOINT` as a fallback |
| `otlp.headers` | `TELEMETRY_OTLP_TOKEN` | sent as `Authorization: Bearer <token>` for an auth-gated endpoint; extra headers via the OTel-standard `OTEL_EXPORTER_OTLP_HEADERS` (`k1=v1,k2=v2`) |
| `otlp.timeout` | `TELEMETRY_OTLP_TIMEOUT` | `3.0` s |
| `otlp.connect_timeout` | `TELEMETRY_OTLP_CONNECT_TIMEOUT` | `1.0` s |
| `otlp.compression` | `TELEMETRY_OTLP_COMPRESSION` | `true` (gzip bodies > 1 KB) |

| `otlp.spool.enabled` | `TELEMETRY_OTLP_SPOOL` | `false` — spans/events go to a Redis list instead of POSTing at terminate; `telemetry:flush` (cron or `--daemon`) ships merged batches |
| `otlp.spool.connection` | `TELEMETRY_OTLP_SPOOL_CONNECTION` | `default` |
| `otlp.spool.key` | `TELEMETRY_OTLP_SPOOL_KEY` | `telemetry:spool` |
| `otlp.spool.max_items` | `TELEMETRY_OTLP_SPOOL_MAX_ITEMS` | `20000` (drop-oldest above) |

Daemon mode for high traffic:
`telemetry:flush --daemon --interval=1 --metrics-interval=15 --max-batch=200`
(one process under supervisor; graceful SIGTERM drain).

After a retryable transport failure (429/5xx, network), an in-process
circuit breaker skips exports for 30 s (or the server's `Retry-After`).

### Prometheus

| Key | Env | Default |
|---|---|---|
| `prometheus.enabled` | `TELEMETRY_PROMETHEUS_ENABLED` | `true` |
| `prometheus.endpoints.*.path` | `TELEMETRY_PROMETHEUS_PATH` | `telemetry/metrics` |
| `prometheus.endpoints.*.middleware` | — | `[AllowIps::class]` |
| `prometheus.endpoints.*.only` | — | `null` (all metrics) |
| `prometheus.allowed_ips` | `TELEMETRY_ALLOWED_IPS` (comma-separated, CIDR ok) | `[]` |
| `prometheus.token` | `TELEMETRY_PROMETHEUS_TOKEN` | — bearer token accepted as an alternative to the IP allowlist, checked with `hash_equals()` |

`AllowIps` (the default endpoint middleware) is **closed outside
`local`/`testing`** unless `allowed_ips` or `token` is set — the same
convention as Horizon/Telescope/Pulse.

Multiple named endpoints are supported — e.g. an `internal` endpoint with
everything and a `public` endpoint filtered to a prefix list:

```php
'endpoints' => [
    'internal' => ['path' => 'internal/metrics', 'middleware' => [AllowIps::class]],
    'public' => ['path' => 'metrics', 'middleware' => ['auth:metrics'], 'only' => ['http', 'queue']],
],
```

#### Exemplars

Every histogram observation made inside a sampled trace carries that
trace's id as an exemplar — click a slow bucket in Grafana, land on the
actual trace that landed in it. No config toggle: it follows
`traces.sample_rate` automatically, and costs nothing when a trace isn't
sampled (the exemplar is simply omitted).

Exemplars have no grammar in the classic Prometheus text format
(`text/plain; version=0.0.4`), so they only render when the scraper
negotiates OpenMetrics via its `Accept` header — content negotiation, not
a setting. Prometheus does this itself when `--enable-feature=exemplar-storage`
is set; scraping with `curl` always gets the classic format unless you
pass `-H 'Accept: application/openmetrics-text'`.

## Traces

| Key | Env | Default |
|---|---|---|
| `traces.sample_rate` | `TELEMETRY_TRACES_SAMPLE_RATE` | `1.0` |
| `traces.max_buffer` | `TELEMETRY_TRACES_MAX_BUFFER` | `5000` |
| `traces.continue_incoming` | `TELEMETRY_TRACES_CONTINUE_INCOMING` | `true` |
| `traces.trust_incoming_sampling` | `TELEMETRY_TRACES_TRUST_INCOMING_SAMPLING` | `true` — disable on public edges so clients can't force sampling |
| `traces.always_sample_errors` | `TELEMETRY_TRACES_ALWAYS_SAMPLE_ERRORS` | `true` — error spans export even from unsampled traces |
| `traces.share_context` | `TELEMETRY_TRACES_SHARE_CONTEXT` | `true` — publishes `trace_id` into Laravel `Context` (Sentry/Flare/logs pick it up) + a Sentry scope tag |
| `traces.response_header` | `TELEMETRY_TRACES_RESPONSE_HEADER` | `X-Trace-Id` — the support reference id on every response; `null` disables. Skipped on publicly cacheable responses (`Cache-Control: public`/`s-maxage`) so caches never replay a stale id |
| `traces.details.mode` | `TELEMETRY_TRACES_DETAILS` | `always` — `tail` keeps cache/query detail spans only for failing or slow traces |
| `traces.details.slow_request_ms` | `TELEMETRY_TRACES_SLOW_REQUEST_MS` | `1000` |
| `traces.details.slow_span_ms` | `TELEMETRY_TRACES_SLOW_SPAN_MS` | `100` |

## Redaction engine

| Key | Env | Default |
|---|---|---|
| `redaction.enabled` | `TELEMETRY_REDACTION` | `true` |
| `redaction.keys` | — | `Redactor::defaultKeys()` — password, secret, token, api_key, authorization, cvv, ssn, … (whole key segments) |
| `redaction.patterns` | — | `Redactor::defaultPatterns()` — JWTs, Bearer/Basic credentials, url userinfo (regex ⇒ replacement) |
| `redaction.safe_keys` | — | `Redactor::defaultSafeKeys()` — exact keys exempt from key-based redaction (`session.driver`, `session.hash`); patterns/hook still apply |
| `redaction.replacement` | — | `[REDACTED]` |

Applied to span attributes, span events, telemetry events AND log
records (message + context) at flush. Custom last-pass hook:
`Telemetry::redactUsing(fn ($key, $value) => ...)` — log messages arrive
with the key `log.message`.

## Events

| Key | Env | Default |
|---|---|---|
| `events.max_buffer` | `TELEMETRY_EVENTS_MAX_BUFFER` | `5000` (force-flush above) |

## Histograms

| Key | Default |
|---|---|
| `default_buckets` | `[1, 5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000]` (ms) |

## Analytics (optional)

An additive layer on top of the telemetry already collected — a shared
`session.id` across browser + server so a whole visit, not just one trace,
can be analysed. Off by default; changes nothing when disabled. See
[Analytics](../production/analytics.md).

| Key | Env | Default |
|---|---|---|
| `analytics.enabled` | `TELEMETRY_ANALYTICS` | `false` |
| `analytics.page_views` | `TELEMETRY_ANALYTICS_PAGE_VIEWS` | `true` — unsampled `analytics.page_view` event per top-level document load |
| `analytics.session.salt` | `TELEMETRY_ANALYTICS_SALT` | — the daily-rotating, salted hash that keeps the built-in `session.id` cookieless; override entirely via `Telemetry::resolveSessionUsing()` |
| `analytics.geo.enabled` | `TELEMETRY_ANALYTICS_GEO` | `false` — country/continent from the client IP via an optional MaxMind database (`composer require geoip2/geoip2`); `Telemetry::resolveClientGeoUsing()` (e.g. Cloudflare `CF-IPCountry`) always wins |
| `analytics.geo.database` | `TELEMETRY_ANALYTICS_GEO_DB` | — path to the MaxMind `.mmdb` file |
| `analytics.user_agent` | `TELEMETRY_ANALYTICS_UA` | `false` — parse `user_agent.original` into low-cardinality `user_agent.name`/`os.name`/`device.type` (families only, never versions) |

## Browser span ingest (optional)

| Key | Env | Default |
|---|---|---|
| `ingest.spans.enabled` | `TELEMETRY_INGEST_SPANS` | `false` — a `POST` endpoint the browser sends its spans to (RUM → distributed tracing) |
| `ingest.spans.path` | `TELEMETRY_INGEST_SPANS_PATH` | `telemetry/spans` |
| `ingest.spans.middleware` | — | `['throttle:300,1']` — add `auth` etc. for logged-in apps |
| `ingest.spans.max_spans` | — | `128` per batch (excess dropped) |
| `ingest.spans.max_attributes` | — | `32` per span |
| `ingest.spans.sample_rate` | — | `1.0` — server-side head sampling (0–1) to cap volume |
| `ingest.spans.asset_path` | `TELEMETRY_INGEST_SPANS_ASSET` | `telemetry/browser.js` — the bundled RUM script `@telemetryBrowser` loads |
| `ingest.spans.browser.{fetch,errors,vitals,sample}` | — | `true`/`true`/`true`/`1.0` — what the bundled script captures. `vitals` reports a `web-vitals` span (LCP/CLS/simplified INP via `PerformanceObserver`) at page hide/unload, when supported |

Add `@telemetryBrowser` to your layout for turnkey RUM (or `@telemetryTraceparent` + your own script). See
[Browser tracing](../production/browser-tracing.md).

## Automatic instrumentation

| Key | Env | Default |
|---|---|---|
| `instrument.requests` | `TELEMETRY_INSTRUMENT_REQUESTS` | `true` |
| `instrument.host_label` | `TELEMETRY_INSTRUMENT_HOST_LABEL` | `true` — `server.address` label on `http.server.*` metrics; routes with a domain pattern report the pattern (`{tenant}.app.example`), keeping wildcard cardinality bounded |
| `instrument.request_headers` | — | `accept, accept-language, content-type, origin, referer, x-forwarded-for, x-requested-with` — span attrs `http.request.header.*`; credentials/session headers are denylisted and never captured |
| `instrument.response_headers` | — | `content-type, cache-control` — span attrs `http.response.header.*` |
| `instrument.jobs` | `TELEMETRY_INSTRUMENT_JOBS` | `true` |
| `instrument.queue_retry_links` | `TELEMETRY_INSTRUMENT_QUEUE_RETRY_LINKS` | `true` — OTel span links between a retried job's attempts (a link, never a parent — attempt N+1 is a sibling of attempt N, both children of the original dispatch). Bridged via the app's own cache, since a retry can land on a different worker (`queue.retry_link_store`/`queue.retry_link_ttl`, default TTL 86400s); a null/array cache driver just means retries go unlinked |
| `instrument.queries` | `TELEMETRY_INSTRUMENT_QUERIES` | `true` |
| `instrument.queries_min_duration` | `TELEMETRY_INSTRUMENT_QUERIES_MIN_DURATION` | `0` ms (record everything; raise as a noise floor) |
| `instrument.query_duplicates` | `TELEMETRY_INSTRUMENT_QUERY_DUPLICATES` | `true` — flags a query that runs identically more than once in the same trace (the actual N+1 smell): `db.query.duplicate.count` root-span tally, `db.queries.duplicated{connection}` counter, and a `db.query.duplicate_detected` OTLP log event carrying the query text. Fires once per distinct query, at the threshold crossing |
| `instrument.query_duplicates_threshold` | `TELEMETRY_INSTRUMENT_QUERY_DUPLICATES_THRESHOLD` | `3` repeats before flagging |
| `instrument.commands` | `TELEMETRY_INSTRUMENT_COMMANDS` | `false` |
| `instrument.gates` | `TELEMETRY_INSTRUMENT_GATES` | `true` — `authorization.checks{ability,result}` counter (gates AND policies) + `gate.check.count`/`gate.denied.count` root-span tallies |
| `instrument.auth` | `TELEMETRY_INSTRUMENT_AUTH` | `true` — `auth.events{event,guard}` (login/logout/failed/lockout/password_reset/registered/verified) |
| `instrument.transactions` | `TELEMETRY_INSTRUMENT_TRANSACTIONS` | `true` — DB transaction spans (nested via savepoints) + `db.transactions.rolled_back` |
| `instrument.models` | `TELEMETRY_INSTRUMENT_MODELS` | `true` — `model.hydrations` root tally (N+1 smell) + `models.events{model,event}` writes + `models.pruned` |
| `instrument.batches` | `TELEMETRY_INSTRUMENT_BATCHES` | `true` — `bus.batches{event,name}` job-batch lifecycle |
| `instrument.redis` | `TELEMETRY_INSTRUMENT_REDIS` | `false` — Redis command spans (key only, never values) + `redis.commands` counter; telemetry's own connections auto-ignored |
| `instrument.redis_ignore_connections` | — | `null` → auto (metric-store + spool connections); set a list to override |
| `instrument.user` | `TELEMETRY_INSTRUMENT_USER` | `true` — tag request spans with `enduser.id` + `enduser.type` (model) + `enduser.guard` (multi-guard safe; never PII) |
| `instrument.resources` | `TELEMETRY_INSTRUMENT_RESOURCES` | `true` — peak memory + CPU per request/job/task; with cboxdk/system-metrics also real RSS + CPU utilization |
| `instrument.profiling` | `TELEMETRY_INSTRUMENT_PROFILING` | `true` — CPU profiling via ext-excimer (PECL, not bundled); a silent no-op without the extension. See [Profiling](#profiling-ext-excimer) below |
| `instrument.scheduled_tasks` | `TELEMETRY_INSTRUMENT_SCHEDULED_TASKS` | `true` — task spans + processed/failed/skipped counters |
| `instrument.views` | `TELEMETRY_INSTRUMENT_VIEWS` | `true` — nested render spans per Blade/PHP view/partial/component (detail-marked); `view.render.count` tally on the root span |
| `instrument.session` | `TELEMETRY_INSTRUMENT_SESSION` | `true` — `session.driver` + `session.hash` (truncated sha256, never the raw id) on request spans; journey queries via TraceQL |
| `instrument.cache` | `TELEMETRY_INSTRUMENT_CACHE` | `false` — cache.operations counters (hit/miss/write/forget) |
| `instrument.cache_spans` | `TELEMETRY_INSTRUMENT_CACHE_SPANS` | `false` — timeline spans per cache op with key/store/duration |
| `instrument.cache_ignore_stores` | — | `[]` — stores never recorded (counters or spans); key-level control via `Telemetry::classifyCacheKeysUsing()` |
| `instrument.mail` | `TELEMETRY_INSTRUMENT_MAIL` | `true` — mail.send spans + counter |
| `instrument.notifications` | `TELEMETRY_INSTRUMENT_NOTIFICATIONS` | `true` — notification.send spans + counter |
| `instrument.http_client` | `TELEMETRY_INSTRUMENT_HTTP_CLIENT` | `true` — outgoing Http-client spans + duration by host/method/status |
| `instrument.exceptions` | `TELEMETRY_INSTRUMENT_EXCEPTIONS` | `true` — `exceptions.reported` counter + a structured, fingerprinted exception record (OTLP log) on every `report()`, incl. handled ones |
| `instrument.exception_source` | `TELEMETRY_INSTRUMENT_EXCEPTION_SOURCE` | `false` — attach the source lines around the throw site (`exception.source`); reads the file, so opt-in |
| `instrument.pennant` | `TELEMETRY_INSTRUMENT_PENNANT` | `true` — `feature.checks{feature,result}` + `feature.unknown{feature}` via `laravel/pennant`'s own events; auto-activates when the package is installed |
| `instrument.horizon` | `TELEMETRY_INSTRUMENT_HORIZON` | `true` — supervisor/master state, long-wait detection, process restarts and OOM via `laravel/horizon`'s own events; auto-activates when installed. Job-level tracing already works without this |
| `instrument.reverb` | `TELEMETRY_INSTRUMENT_REVERB` | `true` — `reverb.messages{direction}`, `reverb.channels{event,type}`, `reverb.connections.pruned` via `laravel/reverb`'s own events, plus live occupancy (`reverb.connections.active`, `reverb.channels.subscribers{type}`) sampled every 15s off message/connection traffic. Auto-activates when installed. Channel names and connection ids are never used as labels |
| `instrument.inertia` | `TELEMETRY_INSTRUMENT_INERTIA` | `true` — `inertia.request` span attribute from the `X-Inertia` header, plus `inertia.version_mismatches` counter + `inertia.version_mismatch` span attribute when the response carries `X-Inertia-Location` (Inertia forcing a full reload after an asset-version bump). Pure response inspection — no `inertiajs/inertia-laravel` dependency needed |
| `instrument.rate_limiting` | `TELEMETRY_INSTRUMENT_RATE_LIMITING` | `true` — `rate_limit.exceeded{limiter}` counter from a 429 response, the driver-agnostic signal (Laravel's `RateLimiter` fires no event). Labeled by the `throttle:<name>` route middleware's limiter name when present, `default` for an inline `throttle:60,1` spec, `unknown` with no throttle middleware at all |
| `instrument.baggage` | `TELEMETRY_INSTRUMENT_BAGGAGE` | `true` — inherit the caller's `Telemetry::context()` dimensions from an incoming W3C `baggage` header (traceparent's standard sibling). Gated on `traces.continue_incoming` too, since baggage is caller-supplied, unvalidated data. Outbound: `Http::withTraceparent()` attaches both headers |
| `instrument.livewire` | `TELEMETRY_INSTRUMENT_LIVEWIRE` | `true` — Livewire component lifecycle via `livewire/livewire`'s own `ComponentHook` API, auto-activates when installed. `livewire.components.mounted`/`.hydrated` counters (mount/hydrate have no "after" phase in that API, so they're counted, not timed); `livewire.render`/`.update`/`.call` detail spans (these DO wrap the real work) carrying `livewire.component` + `livewire.property`/`.method` |
| `instrument.broadcasting` | `TELEMETRY_INSTRUMENT_BROADCASTING` | `true` — `broadcast.count` root-span tally + a `broadcast {event}` detail span per `Broadcaster::broadcast()` call, driver-agnostic (Pusher, Ably, Reverb, Redis, Log, …) via a `Factory`/`Broadcaster` decorator. Carries `broadcasting.driver`, `broadcasting.event`, `broadcasting.channel.count` and a bounded `broadcasting.channels` shape (`public`/`private`/`presence`) — never raw channel names. Reverb's own richer connection/channel-occupancy metrics (`instrument.reverb`) are separate and unaffected |
| `instrument.filesystem` | `TELEMETRY_INSTRUMENT_FILESYSTEM` | `true` — `storage.operations{disk,operation}` counter + a `storage {operation}` detail span per disk operation (put, get, delete, copy, move, …), driver-agnostic (local, S3, whatever Flysystem supports) via a `Factory`/`Filesystem` decorator. Instruments both `Storage::disk('x')->put(...)` and the `Storage::put(...)` default-disk shorthand. Paths are safe on spans (per-occurrence) but never metric labels — same rule as query text |
| `queue.propagate` | `TELEMETRY_QUEUE_PROPAGATE` | `true` |
| `queue.retry_link_store` | `TELEMETRY_QUEUE_RETRY_LINK_STORE` | `null` — the app's default cache store |
| `queue.retry_link_ttl` | `TELEMETRY_QUEUE_RETRY_LINK_TTL` | `86400` seconds |

## Host & process monitor

| Key | Env | Default |
|---|---|---|
| `monitor.interval` | `TELEMETRY_MONITOR_INTERVAL` | `15` s (daemon mode) |
| `monitor.processes` | — | `[]` — name => pgrep pattern (e.g. `'reverb' => 'reverb:start'`) |

`telemetry:monitor --once` from the scheduler (cron mode) or without
`--once` under supervisor (daemon mode). Queue workers additionally
self-report `worker.memory.{php,rss}{queue,pid} (By)` after every job —
no monitor required for worker leak tracking.

## Profiling (ext-excimer)

| Key | Env | Default |
|---|---|---|
| `profiling.period` | `TELEMETRY_PROFILING_PERIOD` | `0.001` s sampling interval |
| `profiling.min_duration_ms` | `TELEMETRY_PROFILING_MIN_DURATION_MS` | `500` — only slower requests/jobs keep their profile (tail-based, like `traces.details.slow_request_ms`) |
| `profiling.top_functions` | `TELEMETRY_PROFILING_TOP_FUNCTIONS` | `20` functions kept per profile |

Requires the PECL `excimer` extension (`pecl install excimer` or your
distro's `php-excimer` package) — a statistical sampling profiler, not
bundled, `extension_loaded()`-guarded everywhere. Without it,
`instrument.profiling` is a silent no-op; `telemetry:doctor` reports
whether it's active. Profiling always runs on a sampled trace (excimer's
own sampling keeps the overhead low), but the result — a bounded "top
functions by sample count" `profile.captured` event — is only kept for
requests/jobs slower than `min_duration_ms`. This is not a full pprof
export; the package has no opinion on a profiling backend.

## Built-in providers

| Key | Env | Default |
|---|---|---|
| `providers.system.enabled` | `TELEMETRY_SYSTEM_METRICS` | `true` |
| `providers.system.cpu_interval` | `TELEMETRY_SYSTEM_CPU_INTERVAL` | `0.1` s (`0` disables CPU utilization) |

The system provider only activates when `cboxdk/system-metrics` is
installed.
