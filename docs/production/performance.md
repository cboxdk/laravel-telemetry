---
title: Performance
description: What telemetry costs and how to tune it
weight: 4
---

# Performance

## Per-operation cost

| Operation | Cost |
|---|---|
| `counter()->inc()` / `gauge()->set()` | ONE Redis command (bookkeeping runs once per process & metric) or an APCu CAS loop |
| `histogram()->record()` | three Redis commands |
| `span()` start/end | in-memory only; export batched at terminate |
| `event()` | in-memory only |
| Observable gauge | zero until scrape/flush |
| Disabled (`TELEMETRY_ENABLED=false`) | ~zero: no listeners, no-op instruments |

## Tuning knobs

- **Sample traces** in high-traffic apps: `TELEMETRY_TRACES_SAMPLE_RATE=0.1`.
  Metrics are unaffected — they aggregate regardless of trace sampling.
- **Turn off query spans** (`TELEMETRY_INSTRUMENT_QUERIES=false`) if you
  have very chatty request/DB patterns; the request span and duration
  histogram remain.
- **Use a dedicated Redis connection** so telemetry writes never queue
  behind cache/queue traffic (and vice versa).
- **APCu store** removes the network hop entirely on single-node setups.

## Hot-path guarantees

- No `KEYS`/`SCAN` anywhere — scrapes are index-driven.
- Span buffer is capped (`traces.max_buffer`) and force-flushes; a
  million-query job cannot exhaust memory.
- Instrument objects are memoized by name — `Telemetry::counter('x')` in a
  loop resolves the same object.
- Every capture path is exception-guarded; telemetry failure never becomes
  application failure.

## When the OTLP backend is down

Exports never retry in-request; a retryable failure trips a per-process
circuit breaker so subsequent requests skip the export entirely for 30 s
(or the server's `Retry-After`). Worst case is one timeout per worker per
cooldown window — not per request.

## Octane

Supported out of the box: trace context resets on `RequestReceived`, and
the shared store makes worker reuse a non-issue for metrics.
