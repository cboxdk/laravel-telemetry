---
title: Metrics
description: Counters, gauges, histograms and the shared store
weight: 2
---

# Metrics

## Counters

Monotonic, cumulative, shared across processes:

```php
Telemetry::counter('orders.created', 'Orders created')->inc();
Telemetry::counter('orders.created')->inc(5, ['tenant' => 'acme']);
```

Negative increments are silently ignored (counters never decrease).
Prometheus output gets the conventional `_total` suffix:
`orders_created_total{tenant="acme"} 5`.

## Gauges

```php
// Pull: evaluated at scrape time. Preferred when the value is queryable.
Telemetry::gauge('queue.depth', fn () => Queue::size(), unit: '{jobs}');

// Push: stored at event time. For values only known when they happen.
Telemetry::gauge('deploy.timestamp')->set(now()->timestamp);
```

Observable callbacks may return a single number or multiple series as
`[value, labels]` pairs. Failing callbacks are dropped for that scrape and
reported — the endpoint never 500s because one source is down.

Keep scrape-time callbacks cheap; they run on every scrape and every
`telemetry:flush`.

## Histograms

```php
Telemetry::histogram('http.client.duration', unit: 'ms')->record($ms);

Telemetry::histogram('import.duration', buckets: [100, 500, 1000, 5000])
    ->time(fn () => $importer->run());
```

Defaults come from `telemetry.default_buckets` (1 ms – 10 s). Buckets are
stored non-cumulatively (OTLP form) and accumulated into cumulative `le`
buckets when rendered for Prometheus.

**Cardinality**: every labelset costs `buckets + 2` store fields. Keep
label values bounded — route patterns, not URLs; status codes, not user
ids.

## The store

| Driver | Write mechanics | Scope |
|---|---|---|
| `redis` | one HASH per metric family + index SETs, MULTI/EXEC writes, `SMEMBERS`+`HGETALL` scrape — never `KEYS`/`SCAN` | cluster-wide |
| `apcu` | CAS loops with explicit key indexes — never iterates the full APCu keyspace | single node |
| `array` | plain arrays | per process (tests) |

Metadata (description, unit, buckets) is written idempotently with the
first sample, so scrapes are self-describing.

There is deliberately **no summary instrument** — quantiles come from
histograms in your backend (`histogram_quantile()` in PromQL).
