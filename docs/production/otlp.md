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

## Latency budget

Trace export happens after the response is sent (terminable middleware),
but still occupies the FPM worker. The transport uses tight timeouts
(3 s total / 1 s connect by default) and never retries in-request;
429/503 responses are classified retryable and simply dropped for that
batch — telemetry is best-effort by design.

If your OTLP backend is slow or far away, put a local OTel collector or
Grafana Alloy next to the app as a fast buffer — supported, just never
required.

## No collector? No problem

The whole point: a bare Laravel app + Redis exports production-grade
telemetry with zero extra infrastructure. Add infrastructure only when you
need buffering, tail sampling or fan-out.
