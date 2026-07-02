# Changelog

All notable changes to `cboxdk/laravel-telemetry` are documented here.

## Unreleased

Initial release.

### Performance

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

### Observability UX

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
