## cboxdk/laravel-telemetry

This application uses cboxdk/laravel-telemetry for metrics, traces, events and logs. Use the `Cbox\Telemetry\Facades\Telemetry` facade.

### Choosing the right instrument

- Something happened → counter: `Telemetry::counter('orders.created')->inc(1, ['tenant' => $slug])`. Counters are monotonic; negative increments are ignored.
- Current value, queryable on demand → observable gauge: `Telemetry::gauge('queue.depth', fn () => Queue::size())`. The callback runs at scrape time — keep it cheap, never do heavy queries in it.
- Current value that goes up AND down at event time → push gauge: `Telemetry::gauge('jobs.in_flight')->increment()` / `->decrement()`.
- Distribution (durations, sizes) → histogram: `Telemetry::histogram('checkout.duration', unit: 'ms')->record($ms)` or `->time(fn () => ...)` to measure a closure.
- A decision or state transition you will query later → `Telemetry::event('autoscale.decision', ['workers' => 7])`.
- Traced work → `Telemetry::span('import.customers', fn ($span) => ...)`. The closure form ends the span, records exceptions and rethrows — prefer it over manual `->end()`.

### Custom dimensions

- Set ambient facets once per request (middleware, after tenant/auth resolution): `Telemetry::context(['team.id' => $id, 'plan' => $plan])` — they flow to every span/event/log AND into dispatched jobs automatically. Never put unbounded ids in metric labels; context is for traces/events/logs.
- For bounded metric dimensions (plan/tier/team) use `Telemetry::labelRequestsUsing(fn ($request) => ['plan' => ...])` — enables p95 per plan in PromQL. Bounded values only.
- Behind catch-all routes, name root spans with `Telemetry::nameRequestsUsing(fn ($request, $response) => 'GET entry:blog')` (bounded names, never ids), and override the useless `http.route` label with `resolveRouteUsing(fn ($request, $response) => 'entry:blog')` so route tables and histograms group by the logical route (bounded — it is a metric label; the literal template is kept as `http.route.template`); add root-span attributes at terminate with `enrichRequestsUsing(fn ($request, $response) => [...])`.
- Cache-heavy subsystems: group or drop cache keys with `Telemetry::classifyCacheKeysUsing(fn (string $store, string $key) => 'group'|null)`; exclude whole stores via `instrument.cache_ignore_stores`.

### Rules

- Metric names: lowercase, dot-namespaced, OTel-style (`orders.created`, `billing.invoices.overdue`). Names match `[a-z][a-z0-9._]*`. A name keeps one instrument type forever.
- Declare units in the instrument (`unit: 'ms'`, `'By'`, `'1'`), never in the name.
- Label values must be bounded: route patterns, status codes, queue names, plans. NEVER user ids, emails, URLs or UUIDs as label values — put those on span attributes or events instead.
- Do not wrap telemetry calls in try/catch and do not check `Telemetry::enabled()` — recording never throws and no-ops when disabled.
- HTTP requests, queue jobs (incl. dispatch counts + wait time), DB queries, scheduled tasks, mail, notifications, outgoing Http-client calls and reported exceptions are auto-instrumented; do not add manual spans/counters for those.
- N+1 smell: `model.hydrations` root-span tally is a proxy count; `instrument.query_duplicates` (default on) names the actual repeated query — a query that runs identically 3+ times in one trace gets a `db.query.duplicate_detected` event with the SQL text, no manual detection needed.
- `laravel/horizon`, `laravel/reverb` and `laravel/pennant`, when installed, are auto-instrumented too (`instrument.horizon`/`.reverb`/`.pennant`, all default on) — supervisor/master state + long-wait detection, WebSocket message/channel counters, and `feature.checks{feature,result}`. Do not add manual instrumentation for these either.
- For high-traffic apps set TELEMETRY_TRACES_DETAILS=tail: full cache/query detail on failing/slow traces, lean skeleton otherwise. Enable instrument.cache_spans freely in this mode.
- Cache keys must NEVER be metric labels; for per-key visibility enable `instrument.cache_spans` (keys on spans are safe).
- Per-route sampling: `->middleware(Sample::never())` on health checks, `Sample::rate(0.01)` on noisy feeds (Cbox\Telemetry\Http\Middleware\Sample). Error spans always export regardless of sampling.
- For outbound HTTP to services you own, use `Http::withTraceparent()->post(...)` so the trace continues across services. Do not add the header for third-party APIs. It also attaches a W3C `baggage` header with any `Telemetry::context()` dimensions (`instrument.baggage`, default on) — the receiving app inherits them, gated on `traces.continue_incoming` since baggage is caller-supplied data.
- Queue retries (`instrument.queue_retry_links`, default on): a retried job's span LINKS (not parents) back to the previous attempt — they're siblings, both children of the original dispatch. Bridged via the app's own cache keyed by job UUID (`queue.retry_link_store`/`_ttl`); a null/array cache driver just means retries go unlinked.
- Queue jobs automatically continue the dispatcher's trace — never propagate trace ids through job properties manually.

### Testing

- Always use `$fake = Telemetry::fake();` in tests — never hit Redis or real exporters.
- Assert with `$fake->assertCounterIncremented('orders.created', ['tenant' => 'acme'])`, `assertSpanRecorded('name', fn ($span) => ...)`, `assertHistogramRecorded()`, `assertEventEmitted()`, plus the negative variants (`assertCounterNotIncremented`, `assertSpanNotRecorded`, `assertEventNotEmitted`).
- Read values with `$fake->counterValue()`, `gaugeValue()`, `histogramCount()`, `recordedSpans()`, `recordedEvents()`.

### Publishing telemetry from a package

Register a provider guarded by `class_exists` so the dependency stays optional:

```php
if (class_exists(\Cbox\Telemetry\Facades\Telemetry::class)) {
    \Cbox\Telemetry\Facades\Telemetry::provider(new MyPackageTelemetryProvider);
}
```

Or inline: `Telemetry::contributes('my-domain', fn (\Cbox\Telemetry\Metrics\Registry $r) => $r->gauge('my_domain.things', fn () => Thing::count()));`

### Operations

- Worker memory leaks: watch `worker_memory_rss_bytes{pid}` (self-reported after every job). For Reverb/Horizon add pgrep patterns to `telemetry.monitor.processes` and schedule `telemetry:monitor --once` every minute.
- Verify any setup change with `php artisan telemetry:doctor` (store round trip, exporter reachability, config warnings).
- CPU profiling: `instrument.profiling` (default on) auto-activates with `ext-excimer` installed — a `profile.captured` event (top functions by sample count) for requests/jobs slower than `profiling.min_duration_ms`. No dependency, no config needed beyond installing the extension.
- Prometheus scrape endpoint: `GET /telemetry/metrics` (config `telemetry.prometheus`). Histogram observations made inside a sampled trace carry that trace's id as an exemplar — no config toggle, follows `traces.sample_rate` — but it only renders when the scraper sends `Accept: application/openmetrics-text` (classic Prometheus text format has no exemplar grammar).
- OTLP metrics need the scheduler: `Schedule::command('telemetry:flush')->everyMinute()->onOneServer();`
- Ship logs as trace-correlated OTLP records by adding the `telemetry` log channel to the stack in `config/logging.php`: `['driver' => 'telemetry', 'level' => 'info']`.
- Full docs live in `vendor/cboxdk/laravel-telemetry/docs/` (start at `docs/getting-started/api-reference.md`).
- Exceptions: every `report()`ed exception emits a structured OTLP record (`exception.type/message/file/line/stacktrace`, `exception.group` fingerprint, `enduser.id` for authenticated users, ambient context) for backend error tracking; `exceptions.reported{exception}` counts by class. Fingerprint groups by throw site, not class.
- Browser/RUM (optional): `TELEMETRY_INGEST_SPANS` opens a bounded, no-token span ingest so the frontend joins the backend trace; the bundled `@telemetryBrowser` script also reports Core Web Vitals (`web_vitals.lcp_ms`/`.cls`/`.inp_ms`) as a `web-vitals` span at page hide (`ingest.spans.browser.vitals`, default on). `TELEMETRY_SOURCEMAPS` + `TELEMETRY_SOURCEMAPS_TOKEN` open a token-gated source-map upload endpoint (CI can hold the secret); `Support\Symbolicator::symbolicateStack($release, $stack)` resolves minified browser stacks to original source at read time.
- Analytics (optional, `TELEMETRY_ANALYTICS`, default off): shared `session.id` (server span + RUM SDK) so a whole visit is one key; unsampled `analytics.page_view` events (OTLP logs, never undercounted) + browser SPA views/engagement/`track()`; optional built-in geo (`TELEMETRY_ANALYTICS_GEO`; precedence hook → Cloudflare `CF-IPCountry` (trusted-proxy gated) → MaxMind) + UA parse (`TELEMETRY_ANALYTICS_UA`) — both also stamped server-side on the browser ingest endpoint. Override via `Telemetry::resolveSessionUsing()` / `resolveClientGeoUsing()`. Analytics = logs not spans (sampling-immune). Zero diff when off.
- Inertia.js (`instrument.inertia`, default on): `inertia.request` span attribute from the `X-Inertia` header; `inertia.version_mismatches` counter + `inertia.version_mismatch` attribute when the response carries `X-Inertia-Location` (Inertia forcing a full reload post-deploy). Pure response inspection, no `inertiajs/inertia-laravel` dependency.
- Livewire (`instrument.livewire`, default on, auto-activates when `livewire/livewire` is installed): `livewire.components.mounted`/`.hydrated` counters (mount/hydrate have no "after" phase in Livewire's `ComponentHook` API, so they're counted not timed); `livewire.render`/`.update`/`.call` detail spans for render/property-update/method-call (these DO wrap real work) carrying `livewire.component` + `livewire.property`/`.method`.
- Broadcasting (`instrument.broadcasting`, default on, driver-agnostic — Pusher/Ably/Reverb/Redis/Log): `broadcast.count` root-span tally + `broadcast {event}` detail span per `Broadcaster::broadcast()` call, carrying `broadcasting.driver`/`.event`/`.channel.count` and a bounded `broadcasting.channels` shape (public/private/presence, never raw channel names). Reverb's own connection/channel-occupancy metrics (`instrument.reverb`) are separate.
- Rate limiting (`instrument.rate_limiting`, default on): `rate_limit.exceeded{limiter}` counter from any 429 response (Laravel's `RateLimiter` fires no event) — labeled by the `throttle:<name>` route middleware's limiter name, `default` for an inline spec, `unknown` with no throttle middleware.
- Filesystem/Storage (`instrument.filesystem`, default on, driver-agnostic — local/S3/whatever Flysystem supports): `storage.operations{disk,operation}` counter + `storage {operation}` detail span per disk operation, covering both `Storage::disk('x')->put(...)` and the `Storage::put(...)` shorthand. Paths are span-only, never metric labels.
- Vapor/Lambda (part of `resource_detection`, no separate toggle): `cloud.provider`/`.platform`, `faas.name`/`.version`/`.instance`/`.max_memory` from AWS Lambda's own env vars, `faas.coldstart` per invocation, `vapor.detected` when deployed via Vapor.
