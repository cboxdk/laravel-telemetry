# Changelog

All notable changes to `cboxdk/laravel-telemetry` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0-alpha.11] - 2026-07-04

Analytics foundation — the shared `session.id` keystone (opt-in, default off).

### Added

- **`telemetry.analytics` config (default off).** The first, additive step
  toward observability-grade analytics on top of the telemetry we already
  collect. Nothing changes when the flag is off.
- **Shared `session.id` across browser + server.** With analytics on, the
  request middleware stamps a `session.id` on the server span, and the
  `@telemetryBrowser` directive propagates the same value to the RUM SDK
  (via `data-session`), so a whole *visit* — not just one trace — is one
  key. The built-in default is a **cookieless**, daily-rotating salted hash
  (IP + UA + host + day), so a raw IP is never a durable grouping key.
- **Two overridable hooks** (the way to plug Cloudflare, a cookie, or your
  own logic):
  - `Telemetry::resolveSessionUsing($request)` — override the `session.id`
    (e.g. `CF-Ray`, a first-party cookie). Returns null → cookieless default.
  - `Telemetry::resolveClientGeoUsing($request)` — supply `client.geo.*`
    from edge headers (e.g. `CF-IPCountry`), no geo database required.
- `session.id` is now a default redaction safe-key (it is the OTel session
  identifier, a hash by construction — never the raw Laravel session id,
  which is only ever recorded, hashed, as `session.hash`).

## [0.1.0-alpha.10] - 2026-07-04

Prometheus metric names now carry unit suffixes.

### Changed (breaking)

- **Prometheus metric names now include the unit as a suffix**, per
  Prometheus/OpenMetrics convention: a `ms` metric renders as
  `<name>_milliseconds` and a `By` metric as `<name>_bytes` (the suffix
  precedes `_total`/`_bucket`/`_sum`/`_count`). Previously the unit lived
  only in the `# HELP` text, so e.g. `http_server_request_duration` is now
  `http_server_request_duration_milliseconds`. This is what the bundled
  dashboards and alerting rules already expected — their latency/duration/
  memory panels and rules now resolve against real series. **If you wrote
  your own PromQL against these metrics, add the unit suffix.** OTLP metric
  names are unaffected (units stay a separate field there).
- The bundled alerting rules (alpha.9) are updated to the suffixed names.

## [0.1.0-alpha.9] - 2026-07-04

Alerting rules, plus a log-channel boot-order fix.

### Added

- **Bundled alerting rules** (`resources/grafana/alerts/telemetry-alerts.yaml`)
  — a standard Prometheus rule file (loadable via `rule_files:`, importable
  into Grafana unified alerting) that fires on the same metrics the
  dashboards chart: request 5xx rate + p95 latency, exception spikes, queue
  failures + backlog, scheduled-task failures, outgoing-HTTP failures, and a
  pipeline self-check (export failing / no data). Per service + environment,
  thresholds tunable.

### Fixed

- **The `telemetry` log driver is now registered in `register()`, not
  `boot()`.** If anything resolved the `log` manager (and built a `stack`
  channel) before this provider booted, the telemetry sub-channel silently
  fell back to an emergency handler and no logs ever reached telemetry.
  Registering the driver earlier guarantees it exists before any channel is
  built.
- **The log handler resolves the telemetry manager lazily, per write.** A
  channel built and cached before `Telemetry::fake()` used to keep the
  original manager, so faked assertions silently missed log events.

## [0.1.0-alpha.8] - 2026-07-04

Source-map symbolication: browser stacks resolve to original source.

### Added

- **Source-map upload + symbolication.** An opt-in, **token-gated**
  `POST {sourcemaps.path}` endpoint (`TELEMETRY_SOURCEMAPS`,
  `TELEMETRY_SOURCEMAPS_TOKEN`) receives your build's `.map` files from CI,
  keyed by release, validated as v3, size-capped, and stored on a configured
  disk. Unlike the world-reachable span ingest, uploads come from CI — which
  *can* hold a secret — so this is bearer-token gated and secure by default
  (a token is required; it can never be left accidentally open).
- **`Support\Symbolicator`** — a self-contained source-map v3 resolver (a
  hand-rolled VLQ decoder, no ext, no library). `symbolicateStack($release,
  $stack)` parses Chrome/Firefox/Safari stack strings and resolves each
  minified frame back to original source/line/column/name, so browser error
  grouping and detail become as good as the backend's. Symbolication is a
  read-time concern — the raw stack is stored as-is; an issues UI resolves it
  on demand, so maps never have to be public. Fail-safe: a missing or bad map
  just leaves the frame minified.

## [0.1.0-alpha.7] - 2026-07-04

Turnkey browser RUM: a bundled, zero-build script + one Blade directive.

### Added

- **`@telemetryBrowser`** — a single Blade directive that emits the
  traceparent meta plus a bundled, dependency-free RUM script (served from
  your app, cached). It roots the browser trace on the server trace,
  records a `document.load` span, instruments `fetch` (propagating
  `traceparent` to same-origin calls so backend spans join the trace;
  cross-origin skipped to avoid CORS preflight), and captures uncaught JS
  errors as error spans. What it captures is configurable
  (`ingest.spans.browser.{fetch,errors,sample}`). Publish it to your own
  build with `vendor:publish --tag=telemetry-assets`. No npm, no build
  step — a full browser SDK remains a separate future package.

## [0.1.0-alpha.6] - 2026-07-04

End-to-end distributed tracing: an optional browser span ingest.

### Added

- **Browser / RUM span ingest** (`TELEMETRY_INGEST_SPANS`, off by default):
  an opt-in `POST {ingest.spans.path}` endpoint the frontend sends its own
  spans to (page load, fetch timings, JS errors). Combined with the
  existing incoming-`traceparent` continuation, browser and backend spans
  share one trace id — a single end-to-end waterfall. Protected by
  throttling, strict payload bounding (capped span count/attributes/name
  lengths, hex-id validation, timestamp clamping) and optional head
  sampling — never a bearer token, since a browser can't hold a secret.
  Every value passes the redaction engine; spans are stamped `browser`.
- `@telemetryTraceparent` Blade directive — renders a
  `<meta name="traceparent">` so the browser can parent its RUM spans to
  the current server trace.
- `Telemetry::ingestSpans()` — export externally-produced spans directly.

## [0.1.0-alpha.5] - 2026-07-04

Drop-in backend error tracking — structured, fingerprinted exception
records (the raw data for an issues view).

### Added

- **Structured exception records** for drop-in backend error tracking.
  Every `report()`ed exception (handled or not) now emits an OTLP log
  record (→ Loki, severity ERROR) with `exception.type`/`message`/`file`/
  `line`/`stacktrace`, the ambient context, and a **Sentry-style
  `exception.group` fingerprint** (class + throw site, `vendor/` skipped)
  so identical failures group into one issue instead of merging by class.
  Captured even out of a trace or when sampled away. Span exception
  events are enriched to match and deduplicated by exception identity
  (a failed job is recorded once, not twice). Opt-in `exception.source`
  (`instrument.exception_source`) attaches the code around the throw site.

## [0.1.0-alpha.4] - 2026-07-04

### Added

- `Telemetry::resolveRouteUsing()` — supply the **logical route** for
  catch-all frameworks. A CMS's single `/{segments?}` template makes every
  page share one `http.route`, collapsing route tables and latency
  histograms into a single bucket. The resolver's (bounded) return value
  now replaces `http.route` on both the span attribute and the metric
  label, so the whole ecosystem — the UI route table, Grafana, TraceQL —
  groups by the logical route. The literal Laravel template is preserved
  as the `http.route.template` span attribute when overridden. This is the
  route counterpart to `nameRequestsUsing` (which shapes only the span
  name).

## [0.1.0-alpha.3] - 2026-07-03

Dashboard fixes: the logs panels returned HTTP 400, and the suite gained
environment/host filters. Also makes the Prometheus scrape endpoint
self-identifying.

### Fixed

- Logs dashboards returned HTTP 400 — Loki rejects a stream selector that
  can match empty (`{service_name=~".*"}`). Template variables now use
  `.+` for their "All" value (valid in both Loki and Prometheus).

### Added

- Dashboard filters for **environment** and **host** across the whole
  suite: `$environment` (`deployment_environment_name`) separates the same
  service across prod/staging/…, and `$host` (`host_name`) breaks down the
  otherwise-aggregated fleet. Both thread through every metric and trace
  query; the overview gains a "Fleet" row (requests by environment, by
  host). The Requests dashboard's domain filter was renamed `$host` →
  `$domain` to free up `$host` for the machine/pod.
- The Prometheus scrape endpoint now stamps the resource identity
  (`service_name`, `service_namespace`, `deployment_environment_name`,
  `host_name`) onto every series — so a single Prometheus scraping many
  apps (or many hosts) can tell them apart, matching what OTLP push
  carries. Churny attrs (deploy id, version) are left off.

## [0.1.0-alpha.2] - 2026-07-03

Env-var naming standardization and first-class OTLP auth. Breaking vs
alpha.1 (expected during alpha) — update `.env` keys per below.

### Added

- **`TELEMETRY_OTLP_TOKEN`** — first-class bearer token for an auth-gated
  OTLP endpoint (e.g. a shared collector), sent as
  `Authorization: Bearer <token>`. No more hand-wiring the `otlp.headers`
  array. Arbitrary headers can also come from the OTel-standard
  `OTEL_EXPORTER_OTLP_HEADERS` (`k1=v1,k2=v2`).

### Changed

- **Env vars standardized**: every variable is now `TELEMETRY_`-prefixed
  and mirrors its config path. Renames:
  `TELEMETRY_ENVIRONMENT` → `TELEMETRY_SERVICE_ENVIRONMENT`,
  `TELEMETRY_DEPLOYMENT` → `TELEMETRY_SERVICE_DEPLOYMENT`,
  `TELEMETRY_TRACE_DETAILS` → `TELEMETRY_TRACES_DETAILS`,
  `TELEMETRY_TRACE_RESPONSE_HEADER` → `TELEMETRY_TRACES_RESPONSE_HEADER`,
  `TELEMETRY_SLOW_REQUEST_MS` → `TELEMETRY_TRACES_SLOW_REQUEST_MS`,
  `TELEMETRY_SLOW_SPAN_MS` → `TELEMETRY_TRACES_SLOW_SPAN_MS`,
  `TELEMETRY_SPOOL_{CONNECTION,KEY,MAX_ITEMS}` → `TELEMETRY_OTLP_SPOOL_*`,
  `TELEMETRY_QUERIES_MIN_DURATION` → `TELEMETRY_INSTRUMENT_QUERIES_MIN_DURATION`.
  The OTLP endpoint's primary variable is now `TELEMETRY_OTLP_ENDPOINT`.
- OpenTelemetry-standard variables are honored as fallbacks for interop —
  `OTEL_EXPORTER_OTLP_ENDPOINT`, `OTEL_EXPORTER_OTLP_HEADERS`,
  `OTEL_SERVICE_NAME` (and the already-supported `OTEL_RESOURCE_ATTRIBUTES`).
  `TELEMETRY_*` wins when both are set.

## [0.1.0-alpha.1] - 2026-07-03

First public release. **Alpha** — the public API may still change before the
1.0 stability guarantee. Everything below is new in this release.

### Added

#### Reliability & correctness

- **Octane**: the gate/policy hook was bound once to the boot-time Gate
  instance, which Octane flushes per request — so `authorization.checks`
  silently died after the first request on every worker. Now re-armed
  via a container `afterResolving` callback (WeakMap-guarded against
  double-counting). Queue instrumentation removed from the request/tick
  reset list — a job's lifecycle is bounded by its own events, not the
  HTTP boundary.
- **Redaction**: a sensitive key holding a non-string value (an int PIN,
  an OTP token, a bool) escaped key-based redaction — the string guard
  ran before the key check. Key redaction now applies regardless of
  value type.
- **Spool**: on a partial ship failure (traces delivered, logs down) the
  whole chunk was requeued, re-shipping the delivered traces as
  duplicates. Now per-signal: only the failed signal requeues.
  Permanently rejected batches (4xx) are dropped instead of wedging the
  spool behind a head-of-line block. Unencodable entries are skipped
  rather than silently poisoning the list.
- **Cardinality**: `notifications.sent` used the FQCN while
  `notifications.failed` used the basename — unified to the basename.
  `bus.batches` dropped its `name` label (apps name batches with ids —
  unbounded). Explicit `redis_ignore_connections` is now unioned with
  the telemetry store/spool connections instead of replacing them, so
  the self-instrumentation guarantee holds.

#### Performance

- Octane hardening (Swoole/RoadRunner/FrankenPHP): half-open
  instrumentation state (in-flight HTTP calls, open transactions,
  pending cache reads) is now flushed on the `RequestReceived`/
  `TickReceived` boundary via the new `ManagesRequestState` contract —
  previously a request that died mid-operation could leak worker memory
  and mis-parent the next request's spans across the long-lived worker.
- OTLP spool + flush daemon for high traffic
  (`TELEMETRY_OTLP_SPOOL=true` + `telemetry:flush --daemon`): requests
  push serialized spans/events to a capped Redis list (one RPUSH, no
  HTTP at terminate); the daemon ships merged batches every second and
  metrics every 15 s. Endpoint-down chunks are requeued, daemon-down
  caps at drop-oldest, SIGTERM drains before exit. Cron mode drains the
  spool once per run.
- Write buffering (`buffer_writes`, default on): metric writes aggregate
  in memory and flush once at request/job terminate — repeated increments
  cost one store command, histogram observations flush as pre-aggregated
  buckets. `MetricStore` gained `mergeHistogram()` for this.

#### Observability UX

- Resource detection (`resource_detection`, default on): every signal
  now carries where it ran — `container.id`/`container.runtime` from
  cgroups (via cboxdk/system-metrics), `k8s.pod.name`,
  `k8s.namespace.name`, `k8s.node.name`, `cloud.region` from downward-API
  env vars, and anything in `OTEL_RESOURCE_ATTRIBUTES` (the OTel
  standard). Config `service.*` stays authoritative. Fills the biggest
  gap for containerized fleets, where `host.name` is a random pod hash.
- Self-observability (`self_metrics`, default on): the package reports
  on itself — `telemetry.export.{duration,count,rejected}`,
  `telemetry.export.circuit_open` (when OTLP is used) and
  `telemetry.spool.depth` (when the spool is enabled). Recorded inline on
  the export path (no feedback loop). New "Telemetry health" row on the
  System dashboard — alert on a stuck circuit or a backing-up spool.
- Broader core-event coverage: authentication lifecycle
  (`auth.events{event,guard}` — login/logout/failed/lockout/…, the
  credential-attack signal), DB transaction spans
  (`db.transaction`, nested via savepoints, + `db.transactions.rolled_back`),
  Eloquent (`model.hydrations` N+1 tally, `models.events{model,event}`,
  `models.pruned`), job batches (`bus.batches{event,name}`), Redis
  command spans (`instrument.redis`, off by default — key only, never
  values, telemetry's own connections auto-ignored), notification
  failures (`notifications.failed`), cache flushes, queue timeouts
  (`queue.jobs.timed_out`) and depth (`queue.size` from queue:monitor),
  and PHP deprecations (`php.deprecations`, via the log channel).
- Deploys are first-class: `service.deployment` auto-detects the git
  sha from `.git/HEAD` when unset (no exec), `telemetry:deploy` emits
  an `app.deployment` marker event from the deploy pipeline, and every
  bundled dashboard renders deploys as annotation lines. Resource
  attributes gained `process.runtime.name/version` and
  `laravel.version`.
- Gate/policy instrumentation (`instrument.gates`, default on):
  `authorization.checks{ability, result}` counter plus
  `gate.check.count`/`gate.denied.count` tallies on the request root
  span — authorization denials become visible without any code changes.
- View render spans (`instrument.views`, default on): every Blade/PHP
  view, partial and component in its own span — real durations, natural
  nesting via engine decoration (rendering always proceeds if telemetry
  fails; unknown engine methods forward). Detail-marked so tail mode
  trims healthy traces; `view.render.count` tally on the root span
  regardless.
- Session dimension (`instrument.session`, default on): `session.driver`
  + `session.hash` (truncated sha256 — never the raw id, it is a
  credential) on request root spans. One TraceQL query follows a whole
  visitor journey; the Users dashboard gained a session-journey panel.
  The redaction engine gained `safe_keys` so these exact keys escape
  key-based redaction while patterns still apply.
- Extension hooks for packages building on top (CMS integrations):
  `nameRequestsUsing()` names root spans behind catch-all routes (an
  explicit `updateName()` is never clobbered by terminate),
  `enrichRequestsUsing()` adds root-span attributes with the final
  response in hand, and `classifyCacheKeysUsing()` groups or drops
  cache keys (`key_group` label / `cache.key.group` attribute) with
  `instrument.cache_ignore_stores` for whole stores.
- The `X-Trace-Id` header is skipped on publicly cacheable responses
  (`Cache-Control: public`/`s-maxage`) — a CDN or static page cache
  must never replay one stale trace id to every visitor.
- Multi-guard user attribution: request spans carry `enduser.type` (the
  model: user/admin/reseller) and `enduser.guard` alongside `enduser.id`,
  so admin #7 and user #7 are distinct identities.
  `resolveUserUsing()` now receives the guard as a second argument.
  Login/Logout events are remembered within the request, so the login
  POST itself and logout requests get user attribution too.
- Redaction engine (`telemetry.redaction`): every span attribute, span
  event (exception messages), telemetry event and log record (message +
  context) passes one choke point at flush — key-segment matching (`password`, `api_key`, …) replaces
  whole values, regex patterns scrub embedded secrets (JWTs,
  Bearer/Basic credentials, url userinfo), and
  `Telemetry::redactUsing()` adds a custom last pass.
- Request spans carry the full connection picture: `server.address` /
  `server.port` (the domain — multi-domain and wildcard apps are
  filterable), `client.address`, `user_agent.original`,
  `network.protocol.version`, redacted `url.query`, and allowlisted
  request/response headers (credentials denylisted, always). Metrics gain
  a `server.address` label (route domain patterns keep wildcard-tenant
  cardinality bounded; `instrument.host_label`); the Requests dashboard
  gained a domain filter + rate-by-domain panel.
- The trace id as a support reference: `X-Trace-Id` on every response
  (`traces.response_header`), `trace_id` published into Laravel `Context`
  at trace start — Sentry (≥ 4.x), Flare and all log channels pick it up
  automatically — plus an explicit Sentry scope tag
  (`traces.share_context`). Requests dashboard gained a trace-id lookup
  panel and a 4xx errors section.
- Tail detail retention (`traces.details.mode=tail`): cache/query detail
  spans are kept only for traces with errors, slow requests or a slow
  query — healthy fast traces ship a lean skeleton with tallies while
  counters/histograms flow unconditionally. Decided at flush with the
  whole trace in memory; buffer-cap flushes always keep details.
- Worker memory self-reporting: `worker.memory.{php,rss}{queue,pid}`
  gauges set after every job — the memory-leak curve, no daemon needed.
- `telemetry:monitor` (node_exporter analog, optional): host CPU (between-
  tick delta), memory, load, disk, network + foreign processes (Reverb,
  Horizon) by pgrep pattern — `--once` for cron mode or a supervisor
  daemon. System provider gained filesystem + network observable gauges.
- Cache timeline spans (`instrument.cache_spans`): every cache
  hit/miss/write/forget as a span with key, store and duration measured
  via Laravel's before/after cache events — the Nightwatch-style
  request timeline; root spans carry cache.event.count/time_ms tallies.
- Outgoing HTTP auto-instrumentation: client spans (host + path, never
  the query string) with a duration histogram by host/method/status and
  a connection-failure counter.
- Queue dispatch tracking: `queue.jobs.dispatched` counter and
  `queue.job.wait_time` histogram (dispatch -> attempt lag) with
  `messaging.wait_time_ms` on consumer spans.
- Reported-exception tracking: `exceptions.reported{exception}` counter
  via the exception handler's reportable hook — HANDLED report()s
  included — plus a non-failing span event on the active span.
- Command metrics (`command.duration`, `commands.{completed,failed}`)
  alongside command spans.
- Per-request query tallies on the root span (`db.query.count`,
  `db.query.time_ms`) via a generic per-trace stat mechanism.
- `Telemetry::resolveUserUsing()` opt-in for richer user attribution
  (name/username) beyond the default PII-free `enduser.id`.
- `deployment.id` resource attribute from `TELEMETRY_DEPLOYMENT`.

- Error spans escape sampling (`traces.always_sample_errors`) — sampled
  apps still export every failing span.
- Per-route sampling middleware: `Sample::rate(0.01)` /
  `Sample::always()` / `Sample::never()`; the re-decision covers the
  active trace including the open request span, and propagates.
- Mail and notification instrumentation (client spans + counters) and
  opt-in cache.operations counters (hit/miss/write/forget, no key
  labels).
- Backdated `laravel.bootstrap` span + `laravel.bootstrap_ms` attribute
  from `LARAVEL_START`, so framework boot shows in the waterfall.

- Per-span resource attribution: every sampled span carries its own
  `php.cpu.time_ms` and `php.memory.delta_bytes`, so trace waterfalls
  show where CPU/memory went (backdated query spans excluded).
- Bundled Grafana dashboard suite: thirteen service-scoped dashboards
  mirroring an APM sidebar (Overview, Requests, Jobs, Commands,
  Scheduled Tasks, Exceptions, Queries, Cache, Outgoing, Mail &
  Notifications, System, Users, Logs) — linked as top-bar tabs with
  shared time/filters, semantic colors, drill-down field links, worker
  leak curves and queue wait-time panels. `telemetry:dashboards`
  imports or exports them.

- Per-request/job/task resource attribution: `php.memory.peak_bytes` and
  `php.cpu.time_ms` span attributes plus memory/CPU histograms
  (getrusage + memory_reset_peak_usage — worker-safe). With
  `cboxdk/system-metrics` installed, spans also carry the real process
  footprint: `process.memory.rss_peak_bytes` and
  `process.cpu.utilization` via a ProcessMetrics tracker per unit of
  work. Opt out via `instrument.resources`.
- Scheduled task monitoring: spans with cron/timezone/overlap attributes,
  `schedule.task.duration` histogram and
  `schedule.tasks.{processed,failed,skipped}` counters — including the
  skipped outcome; background tasks excluded to avoid double collection;
  per-task state isolation in `schedule:run`.
- OTLP serialization survives invalid UTF-8 (substitution instead of
  dropping the batch) and request spans carry
  `http.request.body.size`/`http.response.body.size`.

- `Telemetry::context([...])`: custom dimensions (team, tenant, plan)
  merged into every span, event and log record — inherited by dispatched
  jobs along with `messaging.origin.name` (the dispatch origin).
- `Telemetry::labelRequestsUsing()`: bounded extra labels on the request
  duration histogram — p95/p99 per plan/team in PromQL.

- Request spans carry `enduser.id` (authenticated user id, opt-out via
  `instrument.user`) for per-user trace filtering.
- Queue metric label renamed `job` -> `job.name` (`job_name` in
  Prometheus) — a bare `job` label collides with Prometheus' reserved
  scrape-job label and was silently overwritten by collectors.

#### Foundations & hardening

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

[Unreleased]: https://github.com/cboxdk/laravel-telemetry/compare/v0.1.0-alpha.7...HEAD
[0.1.0-alpha.7]: https://github.com/cboxdk/laravel-telemetry/compare/v0.1.0-alpha.6...v0.1.0-alpha.7
[0.1.0-alpha.6]: https://github.com/cboxdk/laravel-telemetry/compare/v0.1.0-alpha.5...v0.1.0-alpha.6
[0.1.0-alpha.5]: https://github.com/cboxdk/laravel-telemetry/compare/v0.1.0-alpha.4...v0.1.0-alpha.5
[0.1.0-alpha.4]: https://github.com/cboxdk/laravel-telemetry/compare/v0.1.0-alpha.3...v0.1.0-alpha.4
[0.1.0-alpha.3]: https://github.com/cboxdk/laravel-telemetry/compare/v0.1.0-alpha.2...v0.1.0-alpha.3
[0.1.0-alpha.2]: https://github.com/cboxdk/laravel-telemetry/compare/v0.1.0-alpha.1...v0.1.0-alpha.2
[0.1.0-alpha.1]: https://github.com/cboxdk/laravel-telemetry/releases/tag/v0.1.0-alpha.1
