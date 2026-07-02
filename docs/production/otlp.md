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
OTEL_EXPORTER_OTLP_ENDPOINT=https://otlp.example.com:4318
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

Metrics are exported with cumulative temporality — backends see monotonic
series regardless of how many PHP processes contributed.

## High traffic: the spool + flush daemon

At scale, two costs bite: per-request OTLP POSTs at terminate, and a
one-minute metrics cadence that is too coarse. The spool solves both —
the Nightwatch-agent model, with Redis instead of a local socket:

```dotenv
TELEMETRY_OTLP_SPOOL=true
```

```bash
php artisan telemetry:flush --daemon --interval=1 --metrics-interval=15
```

With the spool enabled, requests serialize their spans/events and push
them to a capped Redis list — one `RPUSH`, microseconds, no HTTP in the
request lifecycle. The daemon (one process, under supervisor) drains the
list every `--interval` seconds, merges up to `--max-batch` entries into
a single OTLP request, and flushes metrics every `--metrics-interval`
seconds — sub-second span delivery, sub-minute metrics.

Delivery semantics:

- **Endpoint down** → the chunk is requeued at the front and retried
  next tick; nothing is lost to a collector hiccup.
- **Daemon down** → the list caps at `otlp.spool.max_items` (20 000 by
  default) with drop-oldest semantics; app memory and Redis stay bounded.
- **SIGTERM** → the daemon drains what remains before exiting, so
  restarts don't strand telemetry.

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
