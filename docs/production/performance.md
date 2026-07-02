---
title: Performance
description: What telemetry costs and how to tune it
weight: 4
---

# Performance

## Per-operation cost

| Operation | Cost |
|---|---|
| `counter()->inc()` / `gauge()->set()` | in-memory only — write buffering (default on) aggregates and flushes at terminate |
| `histogram()->record()` | in-memory only; flushes as pre-aggregated buckets |
| Buffer flush (at terminate) | one store command per touched counter/gauge series; a few per histogram series — regardless of how many times each was hit |
| With `TELEMETRY_BUFFER_WRITES=false` | one Redis command per inc/set; three per histogram record |
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

## Write buffering

`buffer_writes` (default on for redis/apcu) aggregates metric writes in
memory and flushes them at request/job terminate — the Laravel Pulse
model. 100 increments of one counter cost one `HINCRBYFLOAT`; an N+1
page's 500 query-duration observations flush as one merged histogram
write. The buffer force-flushes at 1000 pending operations, and
`collect()`/scrapes always flush first, so nothing is ever invisible.
Trade-off: a hard crash (kill -9, segfault) loses the unflushed buffer —
disable buffering if you need write-through semantics.

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
