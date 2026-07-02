# Prior-art survey (2026-07-02)

Condensed findings from a review of the Laravel/PHP telemetry ecosystem,
done before locking the ADRs. Sources: package repos, Packagist, OTLP spec.

## spatie/laravel-prometheus

Active, small, "done" (283★, last human commits 2026-05/06, 0 open issues).

- **Pure pull model**: metrics are *declared* with closures in a service
  provider and only *evaluated at scrape time*. No cross-process state —
  the closure queries the source of truth (DB, Horizon repos) on scrape.
- Only gauge + counter; **no histogram** — no latency distributions.
- `inc()` is a scrape-time re-applied buffer, not a live push; with the
  cache adapter and no wipe it inflates on every scrape. There is no
  "count logins mid-request" story at all.
- `LaravelCacheAdapter` is fetch→mutate→put with **no locking**; multi-node
  correctness explicitly punted to the user.
- Hook-in: one-method `Collector` interface, but **no auto-discovery** —
  users paste collector classes into a published provider stub.
- No test fake; their own tests snapshot the rendered exposition text.

**Steal:** human-label slugging, multi-series-from-one-closure, multiple
named endpoints with separate middleware, swappable render action,
snapshot-testing the exposition text.
**Our gaps to fill:** push instruments, histograms, shared state, fake,
provider auto-discovery.

## spatie/laravel-open-telemetry

Effectively abandoned: 0.0.x for 3+ years, "don't use in production"
banner still up, last feature Jan 2023.

- Despite the name, the wire format is **Zipkin JSON v2**, not OTLP.
- Requires the full `open-telemetry/sdk` dependency graph — used only for
  trace/span ID generation and hex validation (~20 lines to replicate).
- **Exports synchronously per span** at `stop()` — a blocking HTTP POST on
  the request path per span. No batching, no terminate flush.
- Spans are name-keyed in a dict → same-name concurrent spans collide;
  no closure API; no span status/events/exceptions.
- Propagation carries **only the trace ID** (queue payload and traceparent
  parsing both drop the parent span ID) → job/downstream spans appear as
  detached roots.
- Watchers (query, http-client) exist but are dead code, registered
  nowhere, with stray `ray()` debug calls.

**Lesson:** this is the anti-pattern catalogue. Buffer + flush at
terminate, propagate full traceparent, spans as objects, be actually-OTLP.

## promphp/prometheus_client_php

Active (v2.15.1, 2026-05), the de-facto PHP Prometheus client.

- **Redis layout (the good half):** one HASH per metric family (fields =
  encoded label values), one index SET per metric type; scrape =
  `SMEMBERS` + `HGETALL` per family — no `KEYS`/`SCAN` on the hot path.
  Writes are single atomic Lua `EVAL`s.
- **Summaries (the bad half):** one Redis key **per observation** with TTL
  + three levels of wildcard `KEYS` at collect — blocking, O(keyspace).
  `RedisNg` exists solely to fix this with explicit indexes.
- Legacy `APC` adapter regex-iterates the entire APCu keyspace per metric
  at scrape (infamous slow scrapes); `APCng` fixes it with a metainfo
  index key.
- Known race: conditional metadata write loses meta under concurrency
  (issue #23) — write meta idempotently instead.

## laravel/pulse (deep dive — the cross-process proof)

- Capture: recorders buffer entries in memory, flushed at request/job/
  command terminate; hard **buffer cap 5000** triggers early ingest.
  All capture wrapped in `rescue()` — telemetry never throws;
  `Pulse::handleExceptionsUsing()` hook.
- Redis ingest: `XADD` to a stream (one pipelined round trip per flush);
  a `pulse:work` daemon drains with `XRANGE`/`XDEL`; lottery-based
  approximate `XTRIM` (`MINID`, default keep 7d) bounds the stream even if
  the worker dies. Docs insist on a Redis connection separate from queues.
- Aggregation: fixed buckets (`floor(ts/period)*period`) for 4 dashboard
  windows, upserted per (period, bucket, type, key, aggregate); aggregates
  declared at record time (`->count()->avg()...`).
- Sampling per recorder at capture, scaled up at display (`~` prefix).
- Recorders ≈ our providers but config-registered, not self-registering —
  our auto-discovery contract is a real DX improvement.

## Official OTel stack

- `open-telemetry/opentelemetry-auto-laravel`: requires the
  **`ext-opentelemetry` C extension** (deployment friction on managed
  hosting), traces-only in practice, full SDK + protobuf chain for a
  working setup.
- **SDK metrics under FPM are effectively unsolved**: in-process
  aggregation → each worker its own stream; official answer is "run a
  collector". Our shared-store cumulative export sidesteps this.
- **OTLP/http+json is Stable for traces, metrics and logs** (spec).
  JSON mapping traps: hex IDs (not base64), integer enums, lowerCamelCase
  fields, port 4318, `/v1/traces` + `/v1/metrics`; implement 429/503 +
  `Retry-After` retry semantics ourselves.
- `shish/microotlp`: zero-dependency SDK-less OTLP client — proves the
  approach.

## Dead exporters (superbalist, triadev, …)

All were thin wrappers over a Prometheus client lib; all died when the
underlying lib forked/died (jimdo → endclothing → promphp). Owning the
core avoids this failure mode entirely.

## Closest living competitor

`keepsuit/laravel-opentelemetry` (v2.2.3, 2026-06): wraps the full SDK,
traces+metrics+logs, tail sampling, Octane flush modes. Our
differentiation: **no extension, no collector, no protobuf — and metrics
that actually work under FPM.**
