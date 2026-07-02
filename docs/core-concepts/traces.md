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
| Artisan commands | `artisan app:sync` | `instrument.commands` (off by default) |
| Scheduled tasks | `schedule artisan inspire` | `instrument.scheduled_tasks` |
| Mail | `mail.send` (client) | `instrument.mail` |
| Notifications | `notification.send` (client) | `instrument.notifications` |
| Blade/PHP views | `view components.button` — nested, real durations, detail-marked | `instrument.views` |
| Cache counters | `cache.operations{operation,store}` | `instrument.cache` (off by default) |
| Cache timeline spans | `cache.hit`/`miss`/`write`/`forget` with key + duration | `instrument.cache_spans` (off by default) |
| Outgoing HTTP | `GET api.stripe.com` (client) + duration histogram by host | `instrument.http_client` |
| Reported exceptions | `exceptions.reported{exception}` counter + span event — includes HANDLED report()s | `instrument.exceptions` |

Request root spans are named `METHOD /route/{pattern}` by default.
Behind catch-all routes, name them yourself with
`Telemetry::nameRequestsUsing()` — and add attributes at terminate with
`enrichRequestsUsing()`; see [Runtime hooks](../extension-points/hooks.md).
An explicit `updateName()` during the request always survives terminate.

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

Request spans carry `enduser.id`, `enduser.type` (the model:
`user`/`admin`/`reseller`) and `enduser.guard` (the guard that
authenticated) — never name or email. Multi-guard apps stay
disambiguated: admin #7 and user #7 are different identities.
Filter in TraceQL: `{ span.enduser.id = "42" && span.enduser.type = "admin" }`.
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

Nightwatch-style facets — set once, applied everywhere:

```php
// e.g. in middleware, after tenant/team resolution:
Telemetry::context([
    'team.id' => $team->id,
    'team.name' => $team->slug,
    'plan' => $team->plan,
]);
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

Context clears automatically between requests and jobs.

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
  (rate(http_server_request_duration_milliseconds_bucket[5m])))
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

## Tail detail retention

MANY details when it hurts, a lean skeleton when all is well:

```dotenv
TELEMETRY_TRACE_DETAILS=tail
TELEMETRY_SLOW_REQUEST_MS=1000
TELEMETRY_SLOW_SPAN_MS=100
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
span covering framework boot up to the middleware stack, and the request
span carries `laravel.bootstrap_ms`.

## Buffering

Finished spans buffer in memory and flush at terminate — export latency
happens after the response is sent. The buffer is capped
(`traces.max_buffer`, default 5000) and force-flushes when full, so
long-running workers and Octane can't grow unbounded.
