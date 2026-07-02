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

## Lock the endpoint down

The default middleware is an IP allowlist (empty = allow all — fine
locally, not in production):

```dotenv
TELEMETRY_ALLOWED_IPS=10.0.0.0/8,172.16.0.5
```

Or swap in your own auth middleware per endpoint in the config. Metric
names and label values can leak internals — treat the endpoint like an
admin route.

## Scrape cost

A scrape is `SMEMBERS` + one `HGETALL` per metric family, plus your
observable-gauge callbacks. Keep callbacks cheap and bounded; the system
provider's CPU sampling adds `cpu_interval` (default 100 ms) to each
scrape — set `TELEMETRY_SYSTEM_CPU_INTERVAL=0` to skip it.

Running `telemetry:monitor` (the node_exporter analog) moves host
sampling off the scrape path entirely: it pushes the same gauges from a
scheduler tick or a supervisor daemon, with CPU measured as a proper
between-tick delta. Set `TELEMETRY_SYSTEM_METRICS=false` alongside it.

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

`rate()`/`increase()` in PromQL handle occasional resets gracefully.

## Cardinality budget

Every labelset is a Redis hash field; every histogram labelset is
`buckets + 2` fields. Bounded labels (route patterns, status codes,
queue names) keep this tiny. Never label with user ids, URLs or UUIDs.
