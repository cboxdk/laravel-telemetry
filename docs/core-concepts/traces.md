---
title: Traces
description: Spans, context propagation and sampling
weight: 3
---

# Traces

## Spans

```php
$result = Telemetry::span('billing.recalculate', function ($span) use ($tenant) {
    $span->setAttribute('tenant.id', $tenant->id);

    return $service->recalculate($tenant);
});
```

The closure form ends the span for you, records exceptions
(`exception` span event + error status) and rethrows. The manual form:

```php
$span = Telemetry::span('phase.one', attributes: ['shard' => 3]);
$span->addEvent('checkpoint', ['rows' => 5000]);
$span->setStatus(SpanStatus::Ok);
$span->end();
```

Spans are objects, never looked up by name — two concurrent spans with the
same name are simply two spans. Nesting follows the call structure: a span
started while another is active becomes its child.

## Automatic instrumentation

| Source | Span | Config key |
|---|---|---|
| HTTP requests | `GET /users/{id}` (server) | `instrument.requests` |
| Queue jobs | `App\Jobs\Import process` (consumer) | `instrument.jobs` |
| DB queries | `db.query` (client, backdated) | `instrument.queries` |
| DB connects | `db.connect` (client) — the PDO handshake, once per connection per request | `instrument.db_connect` |
| Artisan commands | `artisan app:sync` | `instrument.commands` (off by default) |
| Scheduled tasks | `schedule artisan inspire` | `instrument.scheduled_tasks` |
| Mail | `mail.send` (client) | `instrument.mail` |
| Notifications | `notification.send` (client) | `instrument.notifications` |
| Blade/PHP views | `view components.button` — nested, real durations, detail-marked | `instrument.views` |
| DB transactions | `db.transaction` (nested via savepoints, outcome attribute) | `instrument.transactions` |
| Redis commands | `redis GET` (client, backdated, key only) | `instrument.redis` (off by default) |
| Redis connects | `redis.connect` (client) — the handshake; telemetry's own store/spool skipped | `instrument.redis_connect` |
| Cache counters | `cache.operations{operation,store}` | `instrument.cache` (off by default) |
| Cache timeline spans | `cache.hit`/`miss`/`write`/`forget` with key + duration | `instrument.cache_spans` (off by default) |
| Outgoing HTTP | `GET api.stripe.com` (client) + duration histogram by host | `instrument.http_client` |
| Reported exceptions | `exceptions.reported{exception}` counter + span event — includes HANDLED report()s | `instrument.exceptions` |

Request root spans are named `METHOD /route/{pattern}` by default.
Behind catch-all routes, name them yourself with
`Telemetry::nameRequestsUsing()`, override the useless `http.route` label
with `resolveRouteUsing()` (so route tables and histograms group by the
logical route), and add attributes at terminate with
`enrichRequestsUsing()`; see [Runtime hooks](../extension-points/hooks.md).
An explicit `updateName()` during the request always survives terminate.

Livewire's update endpoint gets logical naming built in: `POST
/livewire/update` identifies nothing, so the root span is named after the
component(s) the request actually touched — `POST livewire:{component}`
for a single-component update, `POST livewire:batch` when one request
updates several. The same value replaces the `http.route` label (the
literal template is preserved as `http.route.template`), and the root
span carries the full component list as a `livewire.components`
attribute. An app-level `resolveRouteUsing()` override still wins.

Query spans are only recorded inside an active trace — no orphan roots
from tinker sessions. The ROOT span additionally carries per-request
tallies — `db.query.count` and `db.query.time_ms` ("12 queries / 48 ms"
at a glance, even when individual query spans are filtered by the noise
floor).

Consumer (job) spans carry `messaging.wait_time_ms` — how long the job
sat in the queue between dispatch and the attempt starting — backed by
the `queue.job.wait_time` histogram and a `queue.jobs.dispatched`
counter on the producer side.

Request spans carry `session.driver` and `session.hash` — a truncated
SHA-256 of the session id (never the id itself; it is an authentication
credential). The hash is stable across a visit, so one TraceQL query
follows a whole visitor journey: `{ span.session.hash = "3f2a…" }`.
Disable with `instrument.session`.

Request spans carry `user.id`, `user.type` (the model:
`user`/`admin`/`reseller`) and `user.guard` (the guard that
authenticated) — never name or email. Multi-guard apps stay
disambiguated: admin #7 and user #7 are different identities.
Filter in TraceQL: `{ span.user.id = "42" && span.user.type = "admin" }`.
The login POST itself and logout requests are attributed too (the
Login/Logout events are remembered within the request). Disable with
`instrument.user`; enrich (explicit PII opt-in) with
`Telemetry::resolveUserUsing(fn ($user, ?string $guard) => [...])`.

## Resource attribution

Request, worker-job and scheduled-task spans carry
`php.memory.peak_bytes` and `php.cpu.time_ms` — the peak memory and CPU
time of THAT unit of work (the process-global peak counter is reset per
request/job/task, so long-lived workers report honestly). Matching
histograms (`http.server.memory.peak`, `http.server.cpu.time`,
`queue.job.*`, `schedule.task.duration`) give p95 memory/CPU per route,
per job — and per custom label dimension. Disable with
`instrument.resources`.

With `cboxdk/system-metrics` installed, spans additionally carry the
process' **real OS footprint** via a ProcessMetrics tracker around each
unit of work: `process.memory.rss_peak_bytes` (sees non-PHP allocations
the PHP allocator misses) and `process.cpu.utilization` — the same
mechanism `cboxdk/laravel-queue-metrics` uses for per-job metrics.

**Every sub-span** also carries its own `php.cpu.time_ms` and
`php.memory.delta_bytes` (allocation delta — may be negative), so the
trace waterfall shows WHERE the CPU and memory went, not just the
totals. Backdated query spans are excluded (their work already happened
when they're recorded).

```traceql
{ name = "order.payment" } | select(span.php.cpu.time_ms, span.php.memory.delta_bytes)
```

```traceql
{ kind = server && span.php.memory.peak_bytes > 134217728 }  # requests over 128 MB
```

## Custom dimensions (context)

Faceted trace search — set the dimensions once, applied everywhere:

```php
// e.g. in middleware, after tenant/team resolution:
Telemetry::context([
    'team.id' => $team->id,
    'team.name' => $team->slug,
    'plan' => $team->plan,
]);
```

A `null` value means **not set**: it removes the dimension rather than
recording an empty one, so an optional value needs no filtering at the call
site, and a dimension can be cleared without `resetContext()` (which would
drop the trace continuation with it).

```php
Telemetry::context(['tenant.id' => $tenant?->id]); // absent when null
Telemetry::context(['tenant.id' => null]);         // removes it again
```

From that point every span, event and telemetry-channel log record in the
request carries the dimensions (span-specific attributes win on
conflict) — and **dispatched jobs inherit them**, together with
`messaging.origin.name` (the dispatching request/command name), so a job
is queryable by team AND traceable back to the exact request that queued
it:

```traceql
{ span.team.name = "checkout" && kind = consumer }
{ span.messaging.origin.name = "POST /demo/orders" }
```

Context clears automatically between requests and jobs. It also crosses
a real HTTP service boundary via the W3C `baggage` header — see
[Context propagation](#context-propagation) below.

## Metric dimensions (bounded!)

Context is traces/events/logs only — metric labels multiply cardinality.
For **bounded** dimensions (plan, tier, team — never raw ids) opt in to
extra request-duration labels:

```php
Telemetry::labelRequestsUsing(fn ($request) => [
    'plan' => $request->user()?->plan ?? 'guest',
]);
```

That enables per-plan latency in PromQL:

```promql
histogram_quantile(0.95, sum by (le, plan)
  (rate(http_server_request_duration_seconds_bucket[5m])))
```

Core labels (`http.route`, method, status) always win over resolver
labels; a throwing resolver is reported and ignored.

## Context propagation

Outbound propagation uses the full W3C `traceparent` — trace id **and**
span id — so downstream spans are children, not detached roots:

- **Queued jobs**: payloads automatically carry the dispatcher's
  traceparent; workers continue it. (Sync jobs run inline in the
  dispatcher's context.)
- **Incoming HTTP**: the middleware continues `traceparent` headers when
  `traces.continue_incoming` is on.
- **Outbound HTTP**: opt in per request with the client macro (deliberate,
  so trace headers never leak to third parties by accident):

```php
Http::withTraceparent()->post($url, $payload);
```

The macro is a no-op when no trace is active.

`Telemetry::context()` dimensions travel the same way — the macro also
attaches a W3C `baggage` header (`team.id=42,plan=pro`, percent-encoded)
whenever context is set, so a downstream SERVICE inherits the SAME
custom dimensions, not just the trace id. The receiving app merges an
incoming `baggage` header back into its own context
(`instrument.baggage`, default on) — gated on `traces.continue_incoming`
too, since baggage is caller-supplied, unvalidated data and should
follow the same trust boundary as continuing the trace itself.

## Span links (retries)

Not every causal relationship is a parent. A retried job's attempt N+1
is a SIBLING of attempt N — both are children of the original dispatch
span, not a continuation of one another — so nesting them as
parent/child would misrepresent the shape. Instead
(`instrument.queue_retry_links`, default on), attempt N+1's span carries
an OTel span **link** back to attempt N's span:

```traceql
{ span.queue.retry = true }
```

The link is bridged via the app's own cache (`queue.retry_link_store`/
`queue.retry_link_ttl`, default 86400s), keyed by the job's stable
UUID — a retry can land on a different worker process, so this can't be
in-memory state. A `null`/`array` cache driver just means retries go
unlinked, same graceful degradation as everything else here.

## The trace id as a support reference

The trace id doubles as the reference that ties error trackers, support
cases and logs back to the trace:

- **`X-Trace-Id` response header** on every traced request
  (`traces.response_header`, set null to disable).
- **Laravel `Context`**: `trace_id` is added at trace start — Sentry
  (≥ 4.x), Flare and every log channel pick it up automatically. An
  explicit Sentry scope tag is set too (`traces.share_context`).
- **Error pages**: `Telemetry::traceId()` is available while the error
  view renders — show it as “quote this reference id to support”.

The full flows (Sentry → trace, support case → trace, error page
recipe) live in [Error tracking & support flow](../production/error-tracking.md).

## Sampling

`traces.sample_rate` (0–1) decides once per trace, at the root. Children
inherit the decision; remote callers' decisions are respected via the
sampled flag. Unsampled spans still exist as context — ids propagate — but
are never buffered or exported.

**Error spans escape sampling** (`traces.always_sample_errors`, default
on): a 10%-sampled app still exports every failing span. The escaped
span's trace may be partial — healthy siblings were dropped under the
head decision.

**Per-route overrides** via the Sample middleware — the re-decision
covers the whole active trace, including the still-open request span:

```php
use Cbox\Telemetry\Http\Middleware\Sample;

Route::get('/health', HealthController::class)->middleware(Sample::never());
Route::post('/checkout', ...)->middleware(Sample::always());
Route::get('/feed', ...)->middleware(Sample::rate(0.01));
```

## Ignoring request paths

Sampling still *measures* a request — `Sample::never()` drops its spans,
but the `http.server.*` metrics, the analytics page view and any failing
span (via the error escape) remain. For traffic you don't want in your
telemetry at all — an observability dashboard reading its own backend, a
load-balancer probe — list the paths instead:

```php
// config/telemetry.php — or TELEMETRY_HTTP_IGNORE_PATHS="health,telemetry-ui,telemetry-ui/*"
'instrument' => [
    'http_ignore_paths' => ['health', 'telemetry-ui', 'telemetry-ui/*'],
],
```

A package that mounts its own routes registers them itself, from a service
provider's `boot()` — data only, nothing is resolved or matched until a
request arrives:

```php
if (class_exists(\Cbox\Telemetry\Facades\Telemetry::class)) {
    \Cbox\Telemetry\Facades\Telemetry::ignorePaths(['telemetry-ui', 'telemetry-ui/*']);
}
```

Both lists apply (`Telemetry::ignoredPaths()` returns the merged set).
Patterns are `Str::is()` globs matched against the request path **without
its leading slash**: `health` is exact, `telemetry-ui/*` covers everything
below the prefix but not the prefix itself, `horizon*` covers both, and `/`
is the site root. The check runs once, first thing in the request
middleware.

### This package ignores its own routes

The same advice applies to the package giving it, so it takes it: the
Prometheus scrape endpoints, the browser span ingest, the RUM asset and the
source map upload are ignored out of the box. A 15-second scrape is ~5,700
requests a day that measure nothing about your app, and it would sit near
the top of your own route tables; the ingest route fires once per real page
view, so telemetry would report itself as traffic.

Nothing is lost by it. Prometheus already records `scrape_duration_seconds`
per target, from the side that can act on it.

The exclusion follows whatever path each route is configured with, so
moving an endpoint moves it too. To measure them like any other route:

```php
'instrument' => [
    'http_ignore_own_routes' => false, // TELEMETRY_HTTP_IGNORE_OWN_ROUTES=false
],
```

**Your own routes stay yours.** Laravel's `/up` is the usual next
candidate — an uptime check hits it every few seconds — but it is not
excluded by default: silently dropping traffic an installation already
records is worse than the noise. Add `'up'` to `http_ignore_paths` when you
want it gone.

What an ignored request gets:

- **No server span, no `http.server.request.duration` / `.memory.peak` /
  `.cpu.time`, no `analytics.page_view`**, no `X-Trace-Id` response header,
  and no incoming `traceparent`/`baggage` is continued.
- **No trace for anything inside it.** The tracer is *suppressed* for the
  request rather than left without a root: the HTTP client, mail and
  notification instrumentations open spans with or without a parent, and
  each would otherwise become a trace root of its own — a dashboard's
  Tempo/Loki calls turning up as hundreds of orphan traces. Spans still
  open as context, so nothing starts a root; none is exported, not even an
  error span (the `always_sample_errors` escape would publish a child whose
  parent was never recorded). `Telemetry::currentSpan()`, `traceId()` and
  `traceparent()` return null, so nothing propagates: a job dispatched or a
  service called from the request starts its own trace.
- **Exceptions are still reported.** `report()` — and so an unhandled
  exception — still writes the `exception` record and increments
  `exceptions.reported`: errors in an ignored path must not vanish. The
  record carries no trace or span id, because there is no trace to link to.
- **Fleet metrics from other instrumentation still count** when they are
  independent of the request span — an outgoing HTTP call still records its
  client-duration histogram, a cache hit still counts. Those are real load
  on the backend, whoever caused it. Instrumentation that only records
  inside a request span (query spans and `db.queries`, Redis, views) records
  nothing.

The suppression is lifted when the request terminates (and by the Octane
reset), so the next request on a long-lived worker is traced normally.
Browser RUM (`@telemetryBrowser`) reports from the page itself — leave the
snippet off pages you ignore.

## Tail detail retention

MANY details when it hurts, a lean skeleton when all is well:

```dotenv
TELEMETRY_TRACES_DETAILS=tail
TELEMETRY_TRACES_SLOW_REQUEST_MS=1000
TELEMETRY_TRACES_SLOW_SPAN_MS=100
```

In `tail` mode, detail spans (cache operations, queries) are kept only
for traces that turned out interesting: an error span anywhere, a
request over `slow_request_ms`, or a single detail span over
`slow_span_ms` (one slow query keeps the WHOLE trace's details). Healthy
fast traces ship the skeleton — root span with all its tallies
(`db.query.count`, `cache.event.count`, resources) — while counters and
histograms flow unconditionally.

The decision happens at flush, when the entire trace is in memory —
tail-based detail retention without a collector. Buffer-cap force
flushes always keep details: a 5000-span request IS interesting.

## Bootstrap visibility

When `LARAVEL_START` is defined (it is, in every standard `public/
index.php`), the request trace includes a backdated `laravel.bootstrap`
span covering framework boot until the request middleware runs, and the
request span carries `laravel.bootstrap_ms`. The request middleware is
appended to the global stack, so the span also covers the app's global
middleware that runs before it.

Only the first request a process serves gets it, because that is the
only request that waited for the boot. Under FPM every request is the
first. A long-lived runtime that defines `LARAVEL_START` once per worker
(NativePHP's persistent interpreter, a custom worker) records it once, for
its first request. Octane doesn't define `LARAVEL_START`, so its requests
have no bootstrap span.

## Request phases

The request span splits into phases, taken from framework events, so the
waterfall shows where the time went without a profiler:

| Span | From → to | Covers |
|---|---|---|
| `laravel.routing` | middleware → `RouteMatched` | Route lookup |
| `laravel.middleware` | → the controller being dispatched | The route's middleware stack |
| `laravel.handler` | → `RequestHandled` | Controller, views, response preparation |
| `laravel.send` | → `Terminating` | Sending the response, streamed bodies included |
| `laravel.terminate` | → request span ends | Session save, `defer()` callbacks, terminable middleware |

Each one is a detail span (tail mode trims it from healthy fast traces)
plus a tally on the request span that is always kept:
`laravel.routing_ms`, `laravel.middleware_ms`, `laravel.handler_ms`,
`laravel.send_ms` and `laravel.terminate_ms`. A boundary that never fires
folds its phase into the next: a 404 matches no route, so it has no
`laravel.routing` and its `laravel.handler` starts at the middleware.

Laravel announces no event between the middleware stack ending and the
controller starting, so that boundary comes from decorating the
controller dispatcher `Route::run()` resolves from the container. Two
kinds of request never reach it and so have no `laravel.middleware`: a
closure route, which is not dispatched through a controller, and a
request a middleware answered itself (an auth redirect, a rate limit).
Their time folds into `laravel.handler`, which is more honest than
reporting a middleware cost of zero for a request that never got past
the middleware.

## Which code answered

The request span carries the action alongside the route, because
`http.route` says which URL pattern matched, not which code ran — routes
share controllers, and one controller answers many routes.

| Attribute | Example |
|---|---|
| `code.namespace` | `App\Http\Controllers\OrderController` |
| `code.function` | `show` |

An invokable controller reports `__invoke`. A closure route has no class,
so it gets `code.function` set to `Closure` and no `code.namespace` —
inventing one would give a UI something false to group by. Both are
bounded by the route table.

Laravel runs `terminate()` after the response has gone out. The request
span covers that work, so spans from `defer()` callbacks stay in the
request's trace. `http.server.request.duration` doesn't: it stops when
the response is sent, because nobody waits for work after that.

## Connect time

`QueryExecuted` and `CommandExecuted` fire only once a connection is already
up, so the handshake — DNS, TCP, TLS, auth — is invisible to query
instrumentation. It is also where a healthy-looking app spends its worst
seconds: a database that answers every query in a millisecond still hangs for
thirty if the connect blocks, and in a query-only waterfall that shows up as
an unexplained gap before the first `db.query`.

`db.connect` and `redis.connect` fill that gap. Laravel resolves PDO lazily,
so the span is emitted the first time a connection is actually used and not
again — a request making two hundred queries has one `db.connect`, not two
hundred. The same lazy resolution is why the timing wraps the resolver closure
rather than `ConnectionFactory::make()`, which returns before any socket is
opened.

These spans are **not** detail-marked. A connect is rare and high-signal, so
it survives `traces.details.mode=tail` trimming — which is exactly when you
want it, since a trace is trimmed for being healthy and kept for being slow.

A connect that throws is recorded and then rethrown unchanged: a twenty-second
connect that ends in a refused connection is the most useful span in the
trace, and losing it to the exception would be losing the answer.

## Half-open client spans

A client span is opened on one framework event and closed on another —
`RequestSending` / `ResponseReceived`, `MessageSending` / `MessageSent`.
Where the framework hands back a different object than it started with,
the two cannot always be paired, and an unpaired span is neither ended nor
exported.

The pairing is by identity wherever an identity survives: outgoing HTTP
keys on the PSR-7 request, which both `Request` wrappers share, so it is
exact even for concurrent `Http::pool()` calls to the same URL. Mail has
no such key — Laravel sets no `Message-ID` before dispatching
`MessageSending`, and most transports clone the message — so it falls
back to the innermost open send.

Deliberately, nothing guesses by what the call *looks* like. Matching a
failure to a span by method/host/path closes whichever lookalike happens
to be open, and two concurrent calls then swap their statuses and
durations. **A missing span is much the lesser evil than one carrying
someone else's outcome, which reads as data.**

These paths therefore go unmatched today:

| Path | Why |
|---|---|
| A redirect | One `ResponseReceived` for several `RequestSending`s; the earlier hops never pair |
| `withOptions(['stream' => true])` | Guzzle's streaming handler clones the request to add `Connection: close` |
| Cloning Guzzle middleware | Same shape as the above |
| `beforeSending` returning a replacement | The **exception** path only; success still pairs through the stored wrapper |
| A mail transport that throws, caught by the caller | Its span stays newest until something else pops it |

### What it costs, and where

The consequence is twofold: that one call is missing from the trace, and
while the span sits on the context stack, later work in the same unit of
work is parented under it.

`ManagesRequestState::flushRequestState()` drops the half-open state, and
it runs on **Octane/NativePHP request and tick boundaries**, and at the
start of each **non-sync queue job** (the latter only when
`instrument.queue` is on). Those are the boundaries that bound it.

Under **FPM there is nothing to bound** — the process ends after the
request, so nothing survives to accumulate.

The case with no boundary is a **long-running CLI process that is neither
Octane nor a queue worker**: an artisan command looping over thousands of
redirected HTTP calls, or mail sends whose transport keeps throwing, holds
one entry per unmatched operation for the life of the command. If you have
such a command, give it explicit boundaries — process in chunks and let the
work happen in queued jobs rather than inline, which restores the per-job
reset.

## Buffering

Finished spans buffer in memory and flush at terminate — export latency
happens after the response is sent. The buffer is capped
(`traces.max_buffer`, default 5000) and force-flushes when full, so
long-running workers and Octane can't grow unbounded.
