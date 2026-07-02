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

Query spans are only recorded inside an active trace — no orphan roots
from tinker sessions.

Request spans carry `enduser.id` (the authenticated user's id — never
name or email) so traces are filterable per user in TraceQL:
`{ span.enduser.id = "42" }`. Disable with `instrument.user`.

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

## Sampling

`traces.sample_rate` (0–1) decides once per trace, at the root. Children
inherit the decision; remote callers' decisions are respected via the
sampled flag. Unsampled spans still exist as context — ids propagate — but
are never buffered or exported.

## Buffering

Finished spans buffer in memory and flush at terminate — export latency
happens after the response is sent. The buffer is capped
(`traces.max_buffer`, default 5000) and force-flushes when full, so
long-running workers and Octane can't grow unbounded.
