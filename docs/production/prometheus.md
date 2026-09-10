---
title: Prometheus
description: Scrape setup, endpoint security and store hygiene
weight: 1
---

# Prometheus in production

## Scrape config

```yaml
scrape_configs:
  - job_name: laravel
    metrics_path: /telemetry/metrics
    scrape_interval: 15s
    static_configs:
      - targets: ['app.example.com']
```

Every app node serves its own endpoint; with the Redis store all nodes
render the same (cluster-wide) values, so scraping any one node — or a
load-balanced VIP — works.

## Endpoint security

The endpoint is **closed by default outside `local`/`testing`** — the
same convention as Horizon/Telescope/Pulse. Any one of these opens it:

```dotenv
# The requester's IP matches (single IPs or CIDR ranges):
TELEMETRY_ALLOWED_IPS=10.0.0.0/8,172.16.0.5

# Or a bearer token — Prometheus's own scrape_config supports
# `authorization.credentials` natively, for scrapers that can't be
# IP-restricted:
TELEMETRY_PROMETHEUS_TOKEN=a-long-random-value
```

```yaml
scrape_configs:
  - job_name: laravel
    metrics_path: /telemetry/metrics
    authorization:
      credentials: a-long-random-value
    static_configs:
      - targets: ['app.example.com']
```

Neither set? Every request 403s outside local/testing — `telemetry:doctor`
reports this as `CLOSED`. Swap in your own auth middleware per endpoint in
the config for anything more bespoke (SSO, mTLS, …). Metric names and
label values can leak internals — treat the endpoint like an admin route.

## Exemplars

Every histogram observation made inside a sampled trace carries that
trace's id as an exemplar — click a slow bucket in Grafana, land on the
actual trace that landed in it. No config toggle: it follows
`traces.sample_rate` automatically.

Exemplars have no grammar in the classic Prometheus text format, so they
only render when the scraper negotiates OpenMetrics via its `Accept`
header:

```yaml
scrape_configs:
  - job_name: laravel
    metrics_path: /telemetry/metrics
    scrape_protocols: [OpenMetricsText1.0.0, PrometheusText0.0.4]
    static_configs:
      - targets: ['app.example.com']
```

Prometheus itself needs `--enable-feature=exemplar-storage` to keep what
it receives. Scraping with `curl` gets the classic format unless you pass
`-H 'Accept: application/openmetrics-text'`.

## Scrape cost

A scrape is `SMEMBERS` + one `HGETALL` per metric family, plus your
observable-gauge callbacks. Keep callbacks cheap and bounded; the system
provider's CPU sampling adds `cpu_interval` (default 100 ms) to each
scrape — set `TELEMETRY_SYSTEM_CPU_INTERVAL=0` to skip it.

Running `telemetry:monitor` (the node_exporter analog) moves host
sampling off the scrape path entirely: it pushes the same gauges from a
scheduler tick or a supervisor daemon, with CPU measured as a proper
between-tick delta (`--once` takes one short blocking sample instead,
since a single run has no previous tick to compare against). Set
`TELEMETRY_SYSTEM_METRICS=false` alongside it — running both publishes
each host gauge twice per flush with two different values for the same
series, and the backend keeps whichever lands last.

Its gauges carry a `host` label. They have to: the store is shared by the
fleet, so without one every host would overwrite the same series, and the
`host.name` resource is attached by whoever *exports* — which under
`onOneServer` is not whoever measured. Aggregate across the fleet with
`sum by (state) (system_memory_usage)` and drill in with `{host="..."}`.

## Route caching

Scrape routes are only registered while telemetry and Prometheus are
enabled — rebuild the route cache (`php artisan route:cache`) after
toggling `TELEMETRY_ENABLED` or `TELEMETRY_PROMETHEUS_ENABLED`.

## Counter resets

The store is cumulative and survives deploys (it lives in Redis, not the
process). Wipe deliberately if you need a reset:

```bash
php artisan telemetry:flush --wipe
```

Wipe resets every value but preserves metric definitions and indexes, so
warm FPM/Octane/queue workers keep reporting seamlessly after the reset —
no process recycle needed. `rate()`/`increase()` in PromQL handle
occasional resets gracefully.

## Cardinality budget

Every labelset is a Redis hash field; every histogram labelset is
`buckets + 2` fields. Bounded labels (route patterns, status codes,
queue names) keep this tiny. Never label with user ids, URLs or UUIDs.
