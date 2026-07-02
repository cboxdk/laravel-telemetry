---
title: Performance
description: What telemetry costs and how to tune it
weight: 3
---

# Performance

## Per-operation cost

| Operation | Cost |
|---|---|
| `counter()->inc()` / `gauge()->set()` | one Redis MULTI (3 cmds, 1 round trip) or APCu CAS loop |
| `histogram()->record()` | one Redis MULTI (5 cmds, 1 round trip) |
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

## Octane

Supported out of the box: trace context resets on `RequestReceived`, and
the shared store makes worker reuse a non-issue for metrics.
