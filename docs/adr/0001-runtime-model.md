# ADR-0001: Runtime model — pure PHP, no required infrastructure

- Status: accepted
- Date: 2026-07-02

## Context

The package must be deployable as a plain Composer package. No OTel collector,
no sidecar, no daemon may be *required*. Redis is an acceptable requirement for
shared state — it is already a dependency of the Cbox queue packages.

PHP is shared-nothing: in-process metric state dies with the request. Any
metric that must be observable across requests (Prometheus scrape, OTLP flush)
needs cross-process storage.

## Decision

**Metrics (push instruments — counter/gauge-set/histogram-record):**
writes go straight to a `MetricStore` contract. Drivers:

| Driver  | Use case                              |
|---------|---------------------------------------|
| `redis` | default; multi-node, web + workers    |
| `apcu`  | single-node, no Redis available       |
| `array` | testing / `Telemetry::fake()`         |

Writes are atomic store operations (`HINCRBY` etc.) — no buffering layer,
no flush required for correctness.

**Metrics (pull instruments — observable gauges via callback):**
evaluated at scrape/flush time, never stored. Callbacks are rate-limited
(cached per scrape) to keep scrape cost bounded.

**Prometheus:** a route that renders the `MetricStore` at scrape time.
No collector involved.

**OTLP:** direct HTTP export (`http/json`, no gRPC ext, no protobuf) —
- spans: collected in-memory per request/job, flushed in terminable
  middleware / after job completion;
- metrics: flushed from the `MetricStore` by `telemetry:flush`, registered
  in Laravel's scheduler (every minute).

**Optional (not required):** a `spool` export driver that writes batches to a
Redis stream (`XADD`, lottery-based approximate `XTRIM`, drained with
`XRANGE`/`XDEL` by `telemetry:flush`) — for apps that want zero export
latency in the request. This is opt-in config, never a prerequisite.
This is Laravel Pulse's proven ingest recipe.

## Implementation constraints (from prior-art research, see docs/research/prior-art.md)

**Redis key layout — copy promphp's good half, avoid its bad half:**
- One Redis HASH per metric family; hash fields keyed by encoded label
  values. One index SET per metric type (`SADD` on first write) so scrape
  is `SMEMBERS` + `HGETALL` per family — **never `KEYS`/`SCAN` on the
  scrape path** (promphp's summary implementation is the cautionary tale:
  per-observation keys + wildcard `KEYS` at collect).
- Writes are one atomic Lua `EVALSHA` (bucket + sum + count + meta + index
  in a single round trip). Write metadata idempotently (`HSETNX`), not
  conditionally — promphp's conditional-meta trick loses metadata under
  races (promphp issue #23).
- **No summary instrument.** Histograms cover the need; summaries are what
  forced promphp into per-sample keys.
- Histogram cost: each labelset × (buckets + 2) hash fields. Ship few
  default buckets; document label-cardinality limits.
- Recommend (not require) a Redis connection separate from the queue
  connection, as Pulse's docs do.

**APCu driver:** maintain an explicit key index (APCng pattern) — never
iterate the full APCu keyspace with regex at scrape time.

**OTLP http/json specifics** (spec: Stable for traces, metrics and logs):
`traceId`/`spanId` as hex strings (not base64), enums as integers, field
names lowerCamelCase, `POST /v1/traces` + `/v1/metrics` on port 4318.
Implement the spec's retry semantics (429/503 + `Retry-After`) — there is
no SDK doing it for us. Export **cumulative** temporality from the shared
store — this sidesteps the per-FPM-worker delta-state problem that breaks
the official OTel PHP SDK's metrics under shared-nothing.

**Span buffer:** cap the in-memory span buffer (Pulse caps at 5000 entries)
and force-flush when exceeded — long-running jobs and Octane otherwise grow
unbounded.

## Consequences

- Works on any host that runs Laravel + Redis. Nothing else to operate.
- Export latency for spans lives in the terminable phase (after the response
  is sent) unless the spool driver is enabled.
- Metrics are eventually consistent across nodes via Redis; Prometheus scrapes
  see the merged truth immediately.
