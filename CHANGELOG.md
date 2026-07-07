# Changelog

All notable changes to `cboxdk/laravel-telemetry` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.3.0] - 2026-07-07

### Added

- **Campaign attribution on analytics page views (`TELEMETRY_ANALYTICS_UTM`,
  default off).** With `telemetry.analytics.utm` enabled, the
  `analytics.page_view` event now carries the landing URL's UTM parameters as
  `analytics.utm.source` / `medium` / `campaign` / `content` / `term` (values
  lowercased, trimmed and length-capped; a key appears only when its param is
  present and non-empty), plus a low-cardinality `analytics.click_id` — the
  NAME of the paid ad-network click-id parameter present (`gclid`, `gbraid`,
  `wbraid`, `msclkid`, `dclid`, `ttclid`, `twclid`, `yclid`, first match
  wins), never its unbounded value. `fbclid` is deliberately excluded — Meta
  appends it to organic clicks too, so it is not a reliable paid signal. This
  applies to both the server page view and the browser analytics ingest: the
  browser SDK now sends the landing `url.full` on page-view events, and the
  ingest endpoint derives the same keys from it (never from the ingest
  request's own URL). Strictly additive — nothing is stamped when the flag is
  off. See `Support\CampaignAttribution`.

## [0.2.1] - 2026-07-07

### Added

- **Built-in Cloudflare geo, and server-side geo/UA enrichment at browser
  ingest.** With `TELEMETRY_ANALYTICS_GEO=true`, `client.geo.country` now
  resolves from Cloudflare's `CF-IPCountry` edge header with no MaxMind
  database and no per-request lookup — free on every plan. Precedence is
  `resolveClientGeoUsing()` hook → `CF-IPCountry` → MaxMind. The header is
  only trusted when the request arrives through a trusted proxy — set Laravel
  `TrustProxies` to the immediate hop (your Cloudflare ranges, or your load
  balancer in a `CF → LB → app` chain); it is spoofable and ignored
  otherwise, and the `XX`/`T1` sentinels are dropped. Toggle with
  `TELEMETRY_ANALYTICS_GEO_CF` (default on). The browser ingest endpoint now
  enriches browser spans and events with geo and parsed `user_agent.*` from
  the server-side ingest request — the browser can't know its own country,
  but the ingest request carries it — so nearly all enrichment happens in
  one server-side place.

- **Livewire update requests are named after their component.** Livewire's
  update endpoint is a catch-all — every component update POSTs to the same
  URL, so `http.route` identified nothing. The Livewire instrumentation now
  collects the component names as they mount/hydrate, and the request
  middleware names the logical route `livewire:{component}` (batched
  updates: `livewire:batch`) — on the span, the span name and the request
  metric label — the same way a CMS resolver replaces its `/{segments?}`
  catch-all. The root span carries the full list in `livewire.components`.
  An app's own `resolveRouteUsing()` still wins.

## [0.2.0] - 2026-07-06

First tagged release without a pre-release suffix: the public API
surface described in `docs/getting-started/api-reference.md` is now
considered stable enough for production pilots, and breaking changes
from here on bump the minor version per SemVer 0.x rules.

### Added

- **`enduser.id` on exception records**: the structured exception record
  now carries the authenticated user's id (guests omit it), so issue
  tooling can count affected users per error group, Sentry-style.

### Fixed

- **Unbounded recursion in `FailSafe` during `report()`**: the default
  failure handler is `report()`, and the exception subscriber runs *inside*
  `report()` — so a guarded path that kept failing while an exception was
  being reported (e.g. the `enduser.id` auth lookup with the database down)
  re-entered the subscriber on every cycle and recursed until memory
  exhaustion. `FailSafe::handle()` now carries a re-entrancy latch: a
  telemetry failure that occurs while another telemetry failure is already
  being reported is swallowed instead of re-reported.

## [0.1.0-alpha.17] - 2026-07-06

### Added

- **`db.queries` counter** (`db_queries_total{connection,driver}`): every
  executed query, labeled by the configured connection and its driver —
  the database twin of `redis.commands`, so dashboards can show per-host
  database activity without depending on tail-sampled traces. Bounded
  labels; query text never becomes a label.

- **Cookbook: Deploy annotations** — copy-paste Forge/Envoyer/GitHub
  Actions deploy-script snippets for `telemetry:deploy`, filling
  `--id`/`--notes` from the actual commit being deployed. `--id` already
  auto-detects the git sha without shelling out (`Support\GitVersion`);
  `--notes` has no equivalent, since the commit message can be
  delta-packed and isn't worth parsing git's pack format for. Rather
  than shelling out to `git log` in the deploy script, the recipe uses
  each platform's own deployment variables (Forge's
  `$FORGE_DEPLOY_COMMIT`/`$FORGE_DEPLOY_MESSAGE` env vars, Envoyer's
  `{{ sha }}`/`{{ message }}` template syntax, GitHub Actions'
  `github.sha`/`github.event.head_commit.message`) — no git CLI or
  `.git` presence assumption needed in any of them.

## [0.1.0-alpha.16] - 2026-07-06

### Added

- **A real overhead benchmark** (`tests/Feature/Benchmark/OverheadBenchmarkTest.php`,
  `vendor/bin/pest --group=benchmark`, excluded from `composer test`):
  replaces this project's previously unverified "zero-cost"/"in-memory
  only" claims with actual measured numbers — full default
  instrumentation adds under 1ms median per request on the test harness
  (see docs/production/performance.md for methodology, caveats, and how
  this compares to Nightwatch's published "<3ms" and Sentry-PHP's
  documented lack-of-background-threads constraint, which this package
  shares).
- **`telemetry:doctor` spool-depth check**: the spool is drained
  exclusively by `telemetry:flush` — if the daemon dies or was never
  scheduled, the list just grows until it hits `max_items` and starts
  silently dropping its oldest entries, with no other warning. Doctor
  now reports current depth as a fraction of `max_items`, warns above
  50% full and fails the check above 90%.
- **Filesystem/Storage instrumentation** (`instrument.filesystem`,
  default on): `storage.operations{disk,operation}` counter + a
  `storage {operation}` detail span per disk operation (put, get,
  delete, copy, move, …) — driver-agnostic (local, S3, whatever
  Flysystem supports) via a `Factory`/`Filesystem` decorator.
  Instruments both `Storage::disk('x')->put(...)` and the
  `Storage::put(...)` default-disk shorthand. Paths are safe on spans
  (per-occurrence) but never metric labels, same rule as query text.
  Built carefully after two near-misses in review: routing every
  unknown manager method through the wrapped disk broke
  `Storage::fake()` (which calls `set()`/`createLocalDriver()` — real
  manager methods, not disk operations) — fixed by implementing the
  `Filesystem` interface's operations explicitly on the manager
  decorator too, rather than guessing via `__call()`.
- **OTel span links for queue retries** (`instrument.queue_retry_links`,
  default on): a retried job's attempt N+1 span now links (not parents)
  back to attempt N's span — they're siblings, both children of the
  original dispatch, not a continuation chain. Bridged via the app's
  own cache (`queue.retry_link_store`/`_ttl`, keyed by the job's stable
  UUID) since a retry can land on a different worker process; a
  null/array cache driver just means retries go unlinked, no error.
  New `Tracing\SpanLink` value object and `Span::links()`/OTLP
  `links` array serialization — general-purpose infrastructure, not
  specific to queues, for any future causal-but-non-hierarchical
  relationship between spans.
- **W3C Baggage propagation** (`instrument.baggage`, default on):
  `Telemetry::context()` dimensions (team, tenant, plan, …) now cross a
  real HTTP service boundary, not just process/job boundaries.
  `Http::withTraceparent()` attaches a `baggage` header alongside
  `traceparent`; the receiving app merges an incoming `baggage` header
  back into its own context, gated on `traces.continue_incoming` too
  since baggage is caller-supplied, unvalidated data. New
  `Support\Baggage` class handles the percent-encoded, comma-separated
  wire format (spec's own 8192-byte/180-member budget enforced on encode).
- **Vapor/Lambda FaaS resource attributes** (part of `resource_detection`,
  no separate toggle): `cloud.provider=aws`, `cloud.platform=aws_lambda`,
  `faas.name`/`.version`/`.instance`/`.max_memory` from AWS Lambda's own
  runtime env vars — Vapor included, since it runs on Bref's PHP-FPM
  Lambda layer. `faas.coldstart` is re-evaluated per invocation (true
  only for the first request served by a given execution environment)
  even though the rest of the detected resource is memoized for the
  process. `vapor.detected` flags Vapor specifically.
- **Rate limiter instrumentation** (`instrument.rate_limiting`, default
  on): `rate_limit.exceeded{limiter}` counter from a 429 response — the
  driver-agnostic signal, since Laravel's `RateLimiter` fires no event.
  Labeled by the `throttle:<name>` route middleware's limiter name when
  present, `default` for an inline `throttle:60,1` spec, `unknown` with
  no throttle middleware at all (still counted — any 429 is a rate
  limit signal, from a custom limiter or not).
- **Generic broadcasting instrumentation** (`instrument.broadcasting`,
  default on): `broadcast.count` root-span tally + a `broadcast {event}`
  detail span per `Broadcaster::broadcast()` call — driver-agnostic
  (Pusher, Ably, Reverb, Redis, Log, …), via a `Factory`/`Broadcaster`
  decorator rather than an event listener (Laravel fires no
  "broadcasting" event). Carries `broadcasting.driver`,
  `broadcasting.event`, `broadcasting.channel.count` and a bounded
  `broadcasting.channels` shape (`public`/`private`/`presence`) — never
  raw channel names. Complements, and is unaffected by, Reverb's own
  richer connection/channel-occupancy instrumentation.
- **Livewire component lifecycle** (`instrument.livewire`, default on,
  auto-activates when `livewire/livewire` is installed): registered via
  Livewire's own `ComponentHook` API. `mount`/`hydrate` have no "after"
  phase in that API — the hook is one peer listener among Livewire's own
  internal ones, not a wrapper around them — so those are counters
  (`livewire.components.mounted`/`.hydrated`). `render`/`update`/`call`
  DO wrap the real work (Livewire invokes a returned closure once the
  phase finishes), so those get real detail spans
  (`livewire.render`/`.update`/`.call`), same tail-sampled,
  root-span-tallied shape as view rendering — nested naturally under
  whatever page or request is currently tracing.
- **Inertia.js awareness** (`instrument.inertia`, default on): `inertia.request`
  span attribute from the `X-Inertia` header, plus an `inertia.version_mismatches`
  counter and `inertia.version_mismatch` span attribute when the response
  carries `X-Inertia-Location` — Inertia's own signal that it's forcing a
  full page reload after an asset-version bump. Pure response inspection;
  no dependency on `inertiajs/inertia-laravel` being installed.
- **Histogram exemplars** (no config toggle — follows `traces.sample_rate`):
  every observation made inside a sampled trace carries that trace's id,
  bridging a slow Prometheus bucket to the actual trace that landed in
  it. One exemplar per histogram series (the most recent sampled
  observation), not one per bucket — a deliberate simplification of the
  full spec that needed no store schema rewrite. Only renders when the
  scraper negotiates OpenMetrics via `Accept: application/openmetrics-text`
  (the classic text format has no grammar for it); the default scrape
  response is unchanged.
- **CPU profiling via ext-excimer** (`instrument.profiling`, default on,
  a silent no-op without the PECL `excimer` extension): tail-based, like
  `traces.details.mode`. Excimer's own sampling keeps overhead low, so
  profiling always runs on a sampled trace, but the result — a bounded
  "top functions by sample count" `profile.captured` event — is only kept
  for requests/jobs slower than `profiling.min_duration_ms` (default
  500ms). Not a full pprof export; the package has no opinion on a
  profiling backend, this is enough to see where a slow unit of work
  spent its CPU without one. `telemetry:doctor` reports whether the
  extension is active.
- **Core Web Vitals in the browser RUM script** (`ingest.spans.browser.vitals`,
  default on): `web_vitals.lcp_ms`, `web_vitals.cls` and a simplified
  `web_vitals.inp_ms` (worst single interaction observed, not the full
  spec's high-percentile calculation) via `PerformanceObserver`, shipped
  as one `web-vitals` span at page hide/unload — LCP/CLS are not final
  until then, so reporting on `load` would be wrong. No dependency added;
  still the zero-build script.
- **N+1 / duplicate query detection** (`instrument.query_duplicates`,
  default on): flags a query that runs identically more than once in the
  same trace — `model.hydrations` was already an N+1 *proxy* (a raw
  hydration count); this names the actual repeated query. Laravel's
  `QueryExecuted::$sql` is already parameterized (bindings are separate),
  so the raw SQL text is a solid fingerprint without normalization.
  `db.query.duplicate.count` root-span tally, `db.queries.duplicated{connection}`
  counter, and a `db.query.duplicate_detected` OTLP log event carrying
  the query text — fires once per distinct query, at the configurable
  threshold crossing (`instrument.query_duplicates_threshold`, default
  3), not once per repeat.
- **Horizon, Reverb and Pennant instrumentation** — auto-activates when
  the package is installed (`class_exists`-guarded, never a hard
  dependency):
  - `laravel/horizon`: `horizon.supervisor.processes`/`.paused` and
    `horizon.master.paused`/`.supervisors` gauges pushed from the
    supervisor/master heartbeat; `horizon.long_wait.detected` (counter +
    OTLP log event), `horizon.process.restarts{type}`,
    `horizon.process.out_of_memory{type}` (counter + event), and
    `horizon.jobs.migrated`. Job-level tracing already worked without
    this — Horizon workers fire the standard queue events
    `QueueInstrumentation` listens to; this class deliberately does not
    duplicate that with Horizon's own per-operation Redis events.
  - `laravel/reverb`: `reverb.messages{direction,app}`,
    `reverb.channels{event,type}` and `reverb.connections.pruned{app}`,
    plus live occupancy — `reverb.connections.active{app}` and
    `reverb.channels.subscribers{app,type}` — read directly from Reverb's
    own `MetricsHandler` (no HTTP round trip, since this runs inside the
    `reverb:start` process already) and sampled off existing message/
    connection traffic, throttled to once per 15s per app. Channel names
    and connection ids are never used as labels — only the bounded
    channel type (public/private/presence) and the operator-configured
    app id.
  - `laravel/pennant`: `feature.checks{feature,result}` (every
    `Feature::active()`/`value()` check, cache hit or fresh) and
    `feature.unknown{feature}`. The scope (usually a user/tenant model)
    is never used as a label.
  - New config: `instrument.horizon`, `instrument.reverb`,
    `instrument.pennant` (all default `true`).
- **`telemetry:doctor` now flags a cache/metric-store collision**:
  `php artisan cache:clear` is not prefix-aware — Laravel's Redis cache
  store runs a raw `FLUSHDB`, and the apcu driver calls
  `apcu_clear_cache()` (wipes the whole shared segment machine-wide). If
  telemetry's store shares the same Redis database or apcu segment as
  your cache, a routine cache clear silently empties every metric.
  `telemetry:doctor` now detects and warns about this.

### Fixed

- **`telemetry:flush`'s cron/one-shot path was unguarded**, unlike the
  daemon loop which wraps every equivalent call in `FailSafe::guard`.
  A Redis outage during a scheduled `telemetry:flush` run could dump a
  raw stack trace to the console instead of a clean error. Now guarded
  consistently with the daemon path, failing with a clear message and
  a non-zero exit code (so cron/monitoring still catches it) rather
  than an uncaught exception.

### Changed (breaking security hardening)

- The Prometheus scrape endpoint is now **closed by default outside
  `local`/`testing`** — the same convention as Horizon/Telescope/Pulse.
  Previously an empty `allowed_ips` meant "allow everyone"; now it means
  "closed" unless the app is running in `local`/`testing`. Open it with
  `TELEMETRY_ALLOWED_IPS` (unchanged), the new `TELEMETRY_PROMETHEUS_TOKEN`
  bearer token (checked with `hash_equals()`, matches Prometheus's own
  `authorization.credentials` scrape config), or your own middleware.
  **If you rely on the endpoint being open in production without an IP
  allowlist, set `TELEMETRY_PROMETHEUS_TOKEN` before upgrading** —
  `telemetry:doctor` reports `CLOSED` when a scrape would now 403.

## [0.1.0-alpha.15] - 2026-07-05

### Changed

- Browser span/event ingest (`SpanIngestController`) no longer exports to
  OTLP inline in the request cycle — the built spans/events are stashed on
  the request and exported by a new terminable `Http\Middleware\FlushBrowserIngest`
  (auto-attached to the ingest route), so a slow/down collector can no
  longer add curl latency to this world-reachable endpoint's response.
- Static analysis raised from PHPStan/Larastan level 8 to level 9. A new
  `Support\Cast` helper type-narrows `mixed` values (config reads, framework
  interfaces typed `mixed`) into their expected shape, degrading to a sane
  default instead of PHP's silent, often-wrong scalar coercions.
- `tests/Unit` and `tests/Feature` now mirror `src/`'s subdirectory
  structure, matching `cboxdk/system-metrics` and `cboxdk/laravel-queue-metrics`.

### Fixed

- `TelemetryManager::labelRequestsUsing()` had lost its docblock to a
  copy-paste artifact (an orphaned block sat above `nameRequestsUsing()`
  instead); both are documented correctly now.
- `Span::cpuNowMs()` no longer assumes every `getrusage()` key is present.
- `QueueInstrumentation::completeJob()`'s trailing `flush()`/`resetContext()`
  now run inside `FailSafe::guard`, consistent with the rest of the file.
- Removed a duplicated docblock on `PrometheusRenderer::render()`.
- `Facades\Telemetry`'s `@method` docblock was missing `resolveSessionUsing()`,
  `resolveClientGeoUsing()`, `ingestSpans()` and `ingestEvents()`.
- `ScheduleInstrumentation` cast `$event->task->timezone` straight to
  `string`, which fatal-errors when a schedule uses `->timezone(new
  DateTimeZone(...))` instead of a timezone name string; now handles
  `DateTimeZone`, `string` and the `config('app.timezone')` fallback
  explicitly.
- Added test coverage for `Providers\SystemMetricsProvider` (previously none).

## [0.1.0-alpha.14] - 2026-07-05

Analytics — built-in geo + User-Agent parsing (opt-in, optional deps).

### Added

- **User-Agent parsing** (`TELEMETRY_ANALYTICS_UA`, off). Dependency-free
  `Support\UserAgentParser` turns `user_agent.original` into low-cardinality
  `user_agent.name` / `os.name` / `device.type` (mobile/tablet/desktop/bot) —
  families only, never versions, so they stay safe group-by dimensions.
- **Geo from the IP** (`TELEMETRY_ANALYTICS_GEO` + `..._GEO_DB`, off).
  `Support\GeoResolver` resolves `client.geo.country` (+ continent) via an
  **optional** MaxMind database (`geoip2/geoip2` is a composer *suggest*, not
  a requirement) at collection time, so the raw IP can be dropped. The reader
  is built lazily and cached — no boot-time I/O — and it is a silent no-op
  without the package/database. A `resolveClientGeoUsing()` hook (e.g.
  Cloudflare) always wins over it.

## [0.1.0-alpha.13] - 2026-07-04

Analytics — browser event channel (SPA views, engagement, custom events).

### Added

- **The span ingest also accepts analytics `events`.** The browser posts
  page views / engagement / custom `track()` calls under an `events` key; the
  endpoint re-emits them as **unsampled OTLP log records** (bounded and
  validated like spans) with `analytics.source="browser"` +
  `telemetry.stream="analytics"` markers — the same stream as the server's
  `analytics.page_view`. New `TelemetryManager::ingestEvents()`.
- **`@telemetryBrowser` emits `data-analytics`** when `telemetry.analytics`
  is on, so the SDK turns on its analytics channel: SPA page-view events
  (with `document.referrer`), engagement (visible time + scroll depth), a
  `track(name, props)` conversion API, and screen/DPR device dimensions.
- The [Analytics guide](docs/production/analytics.md) now covers the browser
  channel and a **LogQL cookbook** so a low-traffic LGTM stack answers
  top-pages / views-over-time / referrers / approximate uniques without
  ClickHouse.

## [0.1.0-alpha.12] - 2026-07-04

Analytics — unsampled page-view events.

### Added

- **`analytics.page_view` events (opt-in).** Each top-level document load (a
  GET returning HTML, non-AJAX) emits an `analytics.page_view` **event** — an
  OTLP log record, not a span — so it bypasses trace sampling and a page view
  is never undercounted, even when the full trace is tail-sampled away. It
  carries `trace_id` + `session.id` as the bridge to the waterfall, plus a
  flat one-row-per-view shape (`url.path`, `http.route`, status, referrer,
  `user_agent.original`, `enduser.id`, `client.geo.*`) and a
  `telemetry.stream="analytics"` marker so an OTel Collector can route the
  stream to ClickHouse with no app change. Toggle with
  `TELEMETRY_ANALYTICS_PAGE_VIEWS` (default on when analytics is enabled).
- New [Analytics guide](docs/production/analytics.md).

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

[Unreleased]: https://github.com/cboxdk/laravel-telemetry/compare/v0.2.1...HEAD
[0.2.1]: https://github.com/cboxdk/laravel-telemetry/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/cboxdk/laravel-telemetry/compare/v0.1.0-alpha.17...v0.2.0
[0.1.0-alpha.17]: https://github.com/cboxdk/laravel-telemetry/compare/v0.1.0-alpha.16...v0.1.0-alpha.17
[0.1.0-alpha.16]: https://github.com/cboxdk/laravel-telemetry/compare/v0.1.0-alpha.15...v0.1.0-alpha.16
[0.1.0-alpha.15]: https://github.com/cboxdk/laravel-telemetry/compare/v0.1.0-alpha.14...v0.1.0-alpha.15
[0.1.0-alpha.14]: https://github.com/cboxdk/laravel-telemetry/compare/v0.1.0-alpha.13...v0.1.0-alpha.14
[0.1.0-alpha.13]: https://github.com/cboxdk/laravel-telemetry/compare/v0.1.0-alpha.12...v0.1.0-alpha.13
[0.1.0-alpha.12]: https://github.com/cboxdk/laravel-telemetry/compare/v0.1.0-alpha.11...v0.1.0-alpha.12
[0.1.0-alpha.11]: https://github.com/cboxdk/laravel-telemetry/compare/v0.1.0-alpha.10...v0.1.0-alpha.11
[0.1.0-alpha.10]: https://github.com/cboxdk/laravel-telemetry/compare/v0.1.0-alpha.9...v0.1.0-alpha.10
[0.1.0-alpha.9]: https://github.com/cboxdk/laravel-telemetry/compare/v0.1.0-alpha.8...v0.1.0-alpha.9
[0.1.0-alpha.8]: https://github.com/cboxdk/laravel-telemetry/compare/v0.1.0-alpha.7...v0.1.0-alpha.8
[0.1.0-alpha.7]: https://github.com/cboxdk/laravel-telemetry/compare/v0.1.0-alpha.6...v0.1.0-alpha.7
[0.1.0-alpha.6]: https://github.com/cboxdk/laravel-telemetry/compare/v0.1.0-alpha.5...v0.1.0-alpha.6
[0.1.0-alpha.5]: https://github.com/cboxdk/laravel-telemetry/compare/v0.1.0-alpha.4...v0.1.0-alpha.5
[0.1.0-alpha.4]: https://github.com/cboxdk/laravel-telemetry/compare/v0.1.0-alpha.3...v0.1.0-alpha.4
[0.1.0-alpha.3]: https://github.com/cboxdk/laravel-telemetry/compare/v0.1.0-alpha.2...v0.1.0-alpha.3
[0.1.0-alpha.2]: https://github.com/cboxdk/laravel-telemetry/compare/v0.1.0-alpha.1...v0.1.0-alpha.2
[0.1.0-alpha.1]: https://github.com/cboxdk/laravel-telemetry/releases/tag/v0.1.0-alpha.1
