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

### On the production image, attributed by phase

From `loadtest/inproc.php` and `loadtest/boot.php`, on the production base
image, xdebug off, 600 requests per arm, three interleaved passes. These
resolve to ±0.06 ms, which is why they are the numbers to quote.

| Phase | Cost | Paid |
|---|---|---|
| Service provider at boot | **+0.49 ms** | every PHP-FPM request; once per Octane worker |
| — of which, having it registered at all | +0.22 ms | (config merge, registrations) |
| — of which, wiring the instrumentations | +0.27 ms | |
| Request: middleware + terminate + flush | **+0.58 ms** | every request |
| Per instrumented operation (query, cache op) | **~20 µs** | per operation |

End to end over HTTP, at two concurrent connections so the measurement is
work rather than queueing: **+1.97 ms** per request with an array store
and no exporter, **+2.25 ms** with the Redis store, **+2.49 ms** with a
synchronous OTLP post to a local collector. The gap between +1.07 ms
in-process and +1.97 ms over HTTP is PHP-FPM: a fresh heap per request,
the autoloader, and the container being handed back.

So: **about 2 ms a request under PHP-FPM with everything on.** On a
trivial route that doubles the request, and on a real one that does
30–50 ms of work it is 4–6 %. There is no single feature worth disabling
to recover it — the largest one, resource capture, is ~0.15 ms — and the
one lever that matters is `TELEMETRY_OTLP_SPOOL`, which takes the export
off the request path entirely.

**If you re-measure this, check for xdebug first.** The rig originally
used the `-dev` base image, which ships xdebug with
`xdebug.mode=develop,debug,coverage` — loaded and active. Xdebug costs
per function call, and the telemetry path makes many more calls than the
baseline it is compared against, so it inflated the measured overhead
**four-fold**: +1.79 ms instead of +0.45 ms for the same code. A profiler
that is loaded is not a neutral observer. `loadtest/` now uses the
production tag and disables xdebug regardless.

### The structural constraint (and both answers to it)

In-process telemetry in PHP has a constraint no SDK escapes: **PHP has
no background threads**, so work that isn't handed off to a separate
process happens inside the request — it cannot be silently deferred the
way it can in Node or Python. The ecosystem has two standard answers:

- **The agent shape.** Agent-based APM SDKs keep the request path
  unblocked by fire-and-forgetting the payload to a separate local
  process (a socket write), which does the actual telemetry work. This
  package's spool (`TELEMETRY_OTLP_SPOOL=true` + `telemetry:flush
  --daemon`) is the same shape: requests do one `RPUSH` (plus the
  `LTRIM` that caps the list) and return, and a separate daemon process
  ships the batches — Redis standing in for the local socket.
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

### Measured per-operation, not reasoned

`vendor/bin/pest --group=benchmark` runs these. The absolute figures are
one machine's; the deltas are the package's, and each is the same work
measured with the listener armed and with it absent.

| Hot path | Cost |
|---|---|
| Redis command, `redis_failures` on | **0.6 µs** — and that whole figure is Laravel's dispatch, not our handler, which measures as noise. Without the switch the connection has no dispatcher and nothing is dispatched at all, so this is the true price of hearing that a node died: at 200 commands a request, 0.12 ms. |
| Query, outside a trace | **+1 µs** — the early return an unsampled or untraced request pays. |
| Query, inside a sampled trace | **+11.5 µs** — of which ~6 µs is the tallies and the counter, and ~5.5 µs is the detail span. |
| N+1 detection | **+0.2 µs** — an `xxh3` of the statement. Leave it on. |
| Outgoing HTTP hop | **+20 µs** — against a network call measured in milliseconds. |
| Request middleware + terminate | **0.23 ms** (0.04 handle, 0.19 terminate) |
| Redaction, per span at flush | **~22 µs** — 2.2 ms for a hundred-span trace |
| …with `redaction.pii` on | **+2 µs** a span |
| Listeners registered on defaults | **84** |

That middleware figure was **79 ms** until this was measured, and all of
it was one default. `instrument.resources` takes the OS process
footprint from cboxdk/system-metrics, which reads `/proc/{pid}/stat` on
Linux — microseconds — and shells out to `ps` everywhere else, at ~28 ms
a call, twice per request and twice per job. On a Mac this package was
the dominant cost of every request: fifty-six milliseconds to measure
something that took two. The footprint is now taken only where taking it
is cheap; `resources_process` overrides the decision either way.

The request-level figures in `OverheadBenchmarkTest` are a full kernel
round trip through testbench, which costs ~58 ms with ±10 ms of
variance. A sub-millisecond delta is not separable there, and it is why
that benchmark showed nothing while the middleware was costing 79 ms.
Trust the per-operation table.

Redaction is the one line above that scales with the size of a trace
rather than the number of requests, because it walks every attribute of
every span and event on the way out. It runs after the response, and it
is the price of not shipping a secret to a third-party backend — but a
trace with hundreds of detail spans pays it per span, which is another
reason `traces.details.mode = tail` earns its keep on a busy endpoint.

The one number worth acting on is the query path. Fifty queries in a
request is half a millisecond; five hundred — an N+1 you would want to
know about anyway — is six. Both knobs below apply to it, and the split
above says which one to reach for: `traces.queries.min_duration_ms`
removes the span and keeps the counters, `instrument.queries=false`
removes both.

Everything else is comfortably inside the noise of the work it measures.

## At high volume

The per-operation costs above are what one request pays in CPU. They are
not what decides whether this survives a large fleet — writes are. Three
defaults are chosen for an ordinary application and are wrong above
roughly a hundred requests per second per app.

**Spool the OTLP export.** With `otlp` in `exporters` and
`otlp.spool.enabled` false, every request's terminate makes up to three
synchronous POSTs to the collector — traces, metrics, logs. The response
has already been sent, so the user waits for none of it, but the FPM
worker is not released until the script ends, so the worker waits for
all of it. At 800 requests a second against a collector answering in
20ms that is roughly 48 worker-seconds of pure export per second: you
are provisioning tens of workers to do nothing but post telemetry, and a
collector that gets *slow* — not dead, which the circuit breaker
handles — becomes worker-pool exhaustion in your application.

`TELEMETRY_OTLP_SPOOL=true` replaces the round trip with one Redis push
and lets `telemetry:flush --interval` drain it out of band. It is off by
default because it needs that daemon, and an application without one
would silently stop exporting.

**Sample traces.** `traces.sample_rate` is 1.0. At 800 requests a second
that is 800 root spans plus their children, every second, almost none of
which anyone will read. Metrics are unaffected by trace sampling — the
counters and histograms aggregate regardless — so this costs you nothing
you were actually using.

**Bound every label you add.** The built-ins are bounded by construction
and `queue` is classifiable (see the hooks doc), but
`labelRequestsUsing()` hands you the same gun. A label with a thousand
values multiplies every series it appears on.

None of this has been load-tested. The figures above are per-operation
measurements on one machine and the arithmetic that follows from them;
they are a reason to configure the three things above before going to
that volume, not a substitute for watching what your own stack does when
you get there.

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

## When telemetry itself fails

Every capture and export path runs through `FailSafe::guard`, which
swallows the failure and hands it to `report()`. Two properties matter
more than any signal the package collects, and both are covered by a
test suite (`tests/FailSafety`) that breaks the package on purpose:

- **Nothing reaches the application.** Every instrumented Laravel event
  is dispatched with the container binding poisoned, and the suite
  asserts both that nothing escaped *and* that the guard is what caught
  it — without the second check a listener that silently never ran would
  pass. The middleware paths are covered too: a request is served with
  the metric store refusing every write, an upstream response survives
  malformed cURL stats, and a refused connection still reaches the caller
  as a refused connection.

- **Reporting is throttled to once per distinct failure per minute, per
  process.** The guards sit on paths that run per query and per cache
  operation, so a backend that is down does not fail once — it fails tens
  of thousands of times a minute. Reporting each one would turn a
  degraded dashboard into a log-volume incident on a pipeline usually
  shared with the application. The key is the throw site and the
  exception class, not the message, because a message often carries the
  id that varied.

The same suite runs the package against a native extension that throws
from every call, one that answers with the wrong shapes, and one that is
simply absent.

## When the OTLP backend is down

Exports never retry in-request; a retryable failure trips a circuit
breaker so subsequent requests skip the export entirely for 30 s (or the
server's `Retry-After`). The breaker's deadline lives in APCu where it is
available, so the cooldown is shared by every worker in the pool; without
APCu it is per-process, which under PHP-FPM means per request —
`telemetry:doctor` says which applies.

### Measured, under load, against both kinds of broken collector

From `loadtest/`, with php-fpm (32 workers), a real Redis metric store
and a real collector. Throughput figures belong to that rig and are not
a capacity number for anything; the columns to read are the ones that
compare against the healthy baseline.

These were taken at 32 concurrent connections on a 12-core laptop, so
the absolute latencies are mostly queueing, and on a base image that
still had xdebug active — which inflates everything and inflates the
telemetry path most. **The shape of each row is the finding; the
absolute numbers in this table are not comparable with the per-request
costs above.** They are kept because the comparison between rows is
what matters and all four rows carry the same inflation.

| Collector | rps | p50 | p99 | max | 2xx |
|---|---|---|---|---|---|
| healthy | 348 | 42.7 ms | 96.4 ms | — | 100 % |
| **refusing** (stopped) | 357 | 42.1 ms | 94.1 ms | — | 100 % |
| **stalling**, spool off | 283 | 49.0 ms | 120.4 ms | 3035 ms | 100 % |
| **stalling**, spool on | 214 | 68.1 ms | 177.5 ms | 372 ms | 100 % |

- **A collector that refuses costs nothing.** Connection refused returns
  immediately, and the breaker means it is attempted once per cooldown
  window rather than once per request. Throughput and latency are
  indistinguishable from healthy.
- **A collector that stalls costs one timeout per cooldown window**, and
  that is what the 3 s max is — the configured `otlp.timeout`. Around
  0.1 % of requests paid it; p50 and p99 did not move. The herd is
  inherent and bounded: the breaker can only open once the first failure
  returns, so the requests already in flight when a window opens each pay
  the timeout.
- **The spool takes the stall off the request path entirely** — max drops
  from 3035 ms to 372 ms — and keeps the data: 8 277 payloads queued
  during the outage and all 8 279 shipped afterwards, with the collector
  refusing none of them. It costs throughput while the backend is down
  (214 vs 283 rps) for the honest reason that it is doing work: the
  direct exporter is cheap there precisely because it is discarding.

Cardinality held: after ~50 000 requests the store had nine families of
12–34 hash fields each and none in overflow. Memory held: once the
workers were warm, RSS grew 0.1 KB per request per worker with telemetry
on and 0.0 KB with it off, which is the same as nothing. Resident cost of
having the package loaded at all was ~12 MB per worker.

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

The boundary that matters is the one you do NOT get for free: a
long-running CLI process that is neither Octane nor a queue worker — an
artisan command looping over thousands of HTTP calls or mail sends — has
no reset between iterations, so any half-open client span accumulates for
the life of the command. See
[Traces → Half-open client spans](../core-concepts/traces.md#half-open-client-spans)
for which operations can go unpaired and why the package refuses to guess
its way out of it.
