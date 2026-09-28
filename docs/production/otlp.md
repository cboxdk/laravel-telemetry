---
title: OTLP
description: Direct OTLP/HTTP export to any OpenTelemetry backend
weight: 2
---

# OTLP in production

Exports are plain OTLP/HTTP JSON (spec-stable for traces, metrics and
logs) — Grafana Tempo/Mimir/Loki, Honeycomb, Jaeger, Datadog, an OTel
collector: anything with an OTLP HTTP receiver works, on port 4318 by
default.

```dotenv
TELEMETRY_EXPORTERS=otlp
TELEMETRY_OTLP_ENDPOINT=https://otlp.example.com:4318
```

Authenticated backends:

```php
'otlp' => [
    'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT'),
    'headers' => ['Authorization' => 'Bearer '.env('OTLP_TOKEN')],
],
```

## Schedule the metrics flush

Spans and events push themselves at terminate. Metrics need the scheduler:

```php
Schedule::command('telemetry:flush')->everyMinute()->onOneServer();
```

`onOneServer()` matters in multi-node setups: the store is cluster-wide,
so one flusher is enough (and avoids duplicate datapoints).

That holds for a shared store. When each host keeps its own — APCu,
SQLite, or a Redis on loopback — drop `onOneServer()`: every host has to
flush its own totals.

Either way the series come out right, because the resource always
carries `service.instance.id`, which Prometheus's and Mimir's OTLP
receivers turn into the `instance` label. For a store on this host it is
`host.name`, so each host's totals are their own series. For a shared
Redis it is a fingerprint of the store (`redis-3f9a…`, from its host,
port, database and prefixes, never its credentials): every host derives
the same one, so the fleet's single total stays one series no matter
which host exported it. Set your own with `TELEMETRY_SERVICE_INSTANCE_ID`
or `OTEL_RESOURCE_ATTRIBUTES=service.instance.id=…`.

Metrics are exported with cumulative temporality — backends see monotonic
series regardless of how many PHP processes contributed.

## When the backend rejects a batch

`telemetry:flush` answers for delivery, not just for collection. A batch
the endpoint refused is printed with the exporter, the HTTP status and
the backend's own error body, written to the log at `error` level (under
cron nobody reads stdout), and the command **exits non-zero**:

```
$ php artisan telemetry:flush
   ERROR  Flushed 57 metric families: 0 of 1 exporter accepted the batch.

  otlp ...... rejected: HTTP 400: {"code":3,"message":"unknown metric type"}

$ echo $?
1
```

Partial delivery is reported as partial — `2 of 3 exporters accepted the
batch` — and a backend that took the batch but refused some data points
(OTLP partial success) is reported too. One exporter failing never stops
the others from being tried.

Because the exit code is real, the scheduled flush can be monitored like
any other cron job:

```php
Schedule::command('telemetry:flush')
    ->everyMinute()
    ->onOneServer()
    ->emailOutputOnFailure('ops@example.com');
```

Spool mode counts entries held back for the next tick as a failure of
*this* run: nothing is lost — they stay queued — but the endpoint is not
taking data and the exit code says so.

The request path is unchanged: a rejection at terminate is counted in the
`telemetry.export.count{outcome=...}` self-metric and never surfaced to
the user's request.

## High traffic: the spool + flush daemon

At scale, two costs bite: per-request OTLP POSTs at terminate, and a
one-minute metrics cadence that is too coarse. The spool solves both —
an agent-plus-daemon model, with Redis standing in for a local socket:

```dotenv
TELEMETRY_OTLP_SPOOL=true
```

```bash
php artisan telemetry:flush --daemon --interval=1 --metrics-interval=15
```

With the spool enabled, requests serialize their spans/events and push
them to a capped Redis list — two commands (one `RPUSH` for everything
the request carries, and the `LTRIM` that caps the list), microseconds,
no HTTP in the request lifecycle. The daemon (one process, under
supervisor) drains the list every `--interval` seconds, merges up to
`--max-batch` entries into a single OTLP request, and flushes metrics
every `--metrics-interval` seconds — sub-second span delivery,
sub-minute metrics.

### Delivery semantics

Stated precisely, because the useful thing to know about a buffer is
what it does **not** promise.

- **Endpoint down** → the chunk is requeued at the front and retried
  next tick; nothing is lost to a collector hiccup. A `Retry-After` is
  honoured, so an overloaded collector is not hammered.
- **Endpoint returns 4xx** → that chunk is dropped. A payload the
  collector will always reject must not wedge the queue behind it, and
  the drop is counted and reported.
- **Daemon down** → the list caps at `otlp.spool.max_items` (20 000 by
  default) with drop-oldest semantics; app memory and Redis stay
  bounded.
- **SIGTERM** → the daemon drains for up to five seconds and exits.
  Whatever does not fit stays in Redis and the next start picks it up;
  the spool survives restarts. It is deliberately not "drain whatever
  remains", because a supervisor sends SIGKILL about ten seconds later
  and a drain that outlasts that is a kill with extra steps.
- **Redis connection lost mid-drain** → entries are claimed with a
  single atomic `LPOP key count`, so two daemons never take the same
  ones. But if that command executes and its reply is lost, those
  entries are gone: **the spool is at-most-once for the drain
  itself.** It is a buffer in front of an endpoint, not a durable
  queue, and telemetry is lossy by design at several points before it
  (sampling, the span-buffer cap, the circuit breaker). If you need
  acknowledged delivery of telemetry, the thing to run is a local
  collector with its own on-disk queue, and point this package at it.
- **Collector accepts a POST and its response is lost** → that chunk
  is requeued and re-sent, so the other edge is at-least-once.
  Duplicate spans share a span id and a well-behaved backend
  deduplicates them.

Supervisor program:

```ini
[program:telemetry-flush]
command=php /var/www/artisan telemetry:flush --daemon --interval=1
autorestart=true
stopwaitsecs=10
```

Cron mode still works with the spool — `telemetry:flush` (no flags)
drains it once per run. Without the spool, spans export directly at
terminate and only metrics need the scheduler, as above.

**Watch the drain, not just the daemon process.** The spool is drained
*exclusively* by `telemetry:flush` — nothing else touches it. If the
daemon dies (or cron was never scheduled) the list just grows until it
hits `max_items` and starts silently dropping its oldest entries; there
is no other warning. `php artisan telemetry:doctor` reports current
depth as a fraction of `max_items` and fails the check above 90% full
(warns above 50%) — run it from your deploy pipeline or an uptime check,
not just once at setup.

## Latency budget

Trace export happens after the response is sent (terminable middleware),
but still occupies the FPM worker. The transport uses tight timeouts
(3 s total / 1 s connect by default) and never retries in-request;
429/503 responses are classified retryable and simply dropped for that
batch — telemetry is best-effort by design.

If your OTLP backend is slow or far away, enable the spool above — it is
exactly that fast local buffer, without the extra binary. A local OTel
collector or Grafana Alloy works too — supported, just never required.

## No collector? No problem

The whole point: a bare Laravel app + Redis exports production-grade
telemetry with zero extra infrastructure. Add infrastructure only when you
need buffering, tail sampling or fan-out.
