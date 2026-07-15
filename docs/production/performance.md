---
title: Performance
description: What telemetry costs and how to tune it
weight: 4
---

# Performance

## Measured overhead

The claims above ("zero-cost when disabled", "in-memory only") were
qualitative until now. `tests/Feature/Benchmark/OverheadBenchmarkTest.php`
(tagged `--group=benchmark`, excluded from `composer test`/CI) drives a
tight in-process loop through the real HTTP kernel — same middleware
stack, same termination path a production request takes — with no
network or Redis involved (array metric store, null exporter), so the
number reflects this package's OWN code, not a collector's reachability.
Run it yourself: `vendor/bin/pest --group=benchmark`.

Two consecutive runs, 300 requests per scenario (30 discarded as
warm-up), median request time:

| Scenario | Median | Delta vs disabled |
|---|---|---|
| `TELEMETRY_ENABLED=false` (baseline) | 51.4–51.9 ms | — |
| Enabled, array store, no exporter | 51.8–52.5 ms | +0.4–0.6 ms |
| Enabled, array store, null exporter | 52.1–52.5 ms | +0.6–0.7 ms |
| Enabled, tail-sampling mode, null exporter (closest to defaults) | 52.2–52.8 ms | +0.8–0.9 ms |

**Read this as an order of magnitude, not a precise SLA.** The ~51ms
absolute baseline is dominated by Testbench's own per-request test
harness cost (config/container work Testbench does on every simulated
request) — not representative of an already-booted PHP-FPM worker or
Octane, where a real request's baseline is far lower. The number that
matters is the **delta**: full default instrumentation (request span +
route/user/session enrichment, query/view/model/cache listeners,
buffered metric writes, resource capture) adds under **1ms** per
request on this harness, with meaningful run-to-run jitter (the
harness's own p95/max swing 25+ ms from GC and machine noise — measure
median, not max, for signal). Exporting over the network is a
separate, already-bounded cost: OTLP posts run at terminate with a
`timeout`/`connect_timeout` of 3s/1s, and a down collector trips the
per-process circuit breaker after one failure so it costs one timeout
per cooldown window, not per request (see below).

### The structural constraint (and both answers to it)

In-process telemetry in PHP has a constraint no SDK escapes: **PHP has
no background threads**, so work that isn't handed off to a separate
process happens inside the request — it cannot be silently deferred the
way it can in Node or Python. The ecosystem has two standard answers:

- **The agent shape.** Agent-based APM SDKs keep the request path
  unblocked by fire-and-forgetting the payload to a separate local
  process (a socket write), which does the actual telemetry work. This
  package's spool (`TELEMETRY_OTLP_SPOOL=true` + `telemetry:flush
  --daemon`) is the same shape: requests do one `RPUSH` and return, and
  a separate daemon process ships the batches — Redis standing in for
  the local socket.
- **The in-process shape.** External monitoring SDKs without an agent
  do the export work in the request itself, and typically recommend a
  local relay/collector process to absorb it at scale. This package's
  direct OTLP export inherits the same constraint (see "Hot-path
  guarantees" below) — real, synchronous work at terminate, bounded by
  `timeout`/`connect_timeout` and the circuit breaker; the spool is
  this package's answer when that cost matters.

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
  behind cache/queue traffic (and vice versa) — and, more importantly,
  so `php artisan cache:clear` can't destroy your metrics. Neither
  `RedisStore::flush()` (a raw `FLUSHDB`, not prefix-scoped) nor
  `apcu_clear_cache()` (wipes the whole shared segment machine-wide)
  know anything about telemetry's key prefix. If the metric store shares
  a Redis database with your cache, or you use the apcu driver for both,
  a routine cache clear silently empties every dashboard.
  `telemetry:doctor` checks for this and flags it.
- **APCu store** removes the network hop entirely on single-node setups —
  but see the cache:clear warning above; there is no way to protect an
  apcu-backed metric store from `apcu_clear_cache()`.

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

## Octane (Swoole, RoadRunner, FrankenPHP)

All three servers use Octane's long-lived worker model, so one story
covers them:

- **Metrics** are a non-issue by design — state lives in the shared
  store, never in the worker process, so worker reuse changes nothing.
- **Trace context** resets on every `RequestReceived` (and `TickReceived`
  for the tick worker): trace id, sample decision, context dimensions
  and per-trace tallies are cleared, so no request inherits the previous
  one's trace.
- **Half-open instrumentation state** is flushed on the same boundary. A
  request that dies between a "before" and "after" event (an in-flight
  HTTP call whose response never arrives, an open transaction, a pending
  cache read) would otherwise leave a stale entry in the long-lived
  instrumentation singleton — a slow worker-memory leak and a
  mis-parenting risk. `ManagesRequestState::flushRequestState()` drops
  it; the cache/HTTP/mail/notification/transaction/command/queue
  instrumentations all implement it.
- **The OTLP circuit breaker** is intentionally a per-worker static — a
  dead collector costs each worker one timeout, not one per request.

Nothing to configure; detection is automatic (the Octane event classes'
presence). Under FPM none of this runs — the process ends after each
request anyway.
