---
title: Error tracking & support flow
description: Correlate Sentry/Flare issues, support cases and traces via the trace id
weight: 6
---

# Error tracking & the support flow

The trace id is the universal reference that ties an error report, a
support case and the full technical context (the Tempo waterfall)
together. The package publishes it everywhere automatically.

## What happens out of the box

At trace start (request, job, scheduled task) the package:

1. Adds `trace_id` to **Laravel's `Context` facade** — picked up
   automatically by sentry-laravel (≥ 4.x), Spatie Flare/Ignition and
   every log channel.
2. Sets an explicit **Sentry scope tag** (`trace_id`) when the Sentry SDK
   is installed — visible on the issue page even on older SDK versions.
3. Exposes **`X-Trace-Id` on every response**
   (`traces.response_header`, disable with null).

Error spans always export — even from sampled-down traces
(`always_sample_errors`) — so the id on a Sentry issue reliably resolves
to a trace.

## Flow: Sentry issue → trace

The issue carries the `trace_id` tag (and the Laravel Context block).
Paste it into the **trace lookup** on the bundled Requests dashboard, or
open Grafana Explore → Tempo → the id. You land on the exact waterfall:
queries, cache ops, the queued job, memory/CPU, the user.

Optional deep link: in Sentry → Settings → Issue Links (or a simple
saved bookmark), template
`https://grafana.example.com/explore?schemaVersion=1&panes={"t":{"datasource":"tempo","queries":[{"query":"{{ tag.trace_id }}"}]}}`.

## Flow: support case → trace

Show the id to the user on your error page — “we've been notified;
quote this reference id if you contact support”:

```blade
{{-- resources/views/errors/500.blade.php --}}
<p>We've been notified automatically. If you contact support about
   this problem, quote this reference id:</p>
@if ($traceId = Cbox\Telemetry\Facades\Telemetry::traceId())
    <code>{{ $traceId }}</code>
@endif
```

`Telemetry::traceId()` works during error rendering — the request span
is still active. API consumers get the same id from the `X-Trace-Id`
response header. Support pastes the id into the Requests dashboard's
trace lookup and sees exactly what that user hit.

## Flow: trace → error tracker

Error spans carry `exception.type` and `exception.message` as span
events, and `exceptions.reported{exception}` counts every `report()` —
from a spike in the Exceptions dashboard, the class name + time window
finds the Sentry issue.

## Flare / other trackers

Anything that snapshots Laravel `Context` gets `trace_id` for free.
For trackers that don't, add it manually where you configure them:

```php
Flare::context('trace_id', Telemetry::traceId());
// Bugsnag::registerCallback(fn ($report) => $report->setMetaData([
//     'telemetry' => ['trace_id' => Telemetry::traceId()],
// ]));
```

Disable all automatic publishing with
`TELEMETRY_TRACES_SHARE_CONTEXT=false`.
