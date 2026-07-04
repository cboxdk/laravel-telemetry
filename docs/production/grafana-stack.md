---
title: Grafana stack
description: Our recommended backend — Prometheus/Mimir, Tempo, Loki, one Grafana
weight: 3
---

# The Grafana stack (recommended)

This package is backend-agnostic, but we have an opinion. The LGTM stack
(Loki, Grafana, Tempo, Mimir/Prometheus) is what we design against: fully
OTLP-capable, self-hostable or managed (Grafana Cloud), and the trace/log/
metric correlation story is best in class.

> Just want it running locally for dev, test or CI? Skip to the
> [Local LGTM stack](../cookbook/local-lgtm-stack.md) cookbook — one
> container, first trace in a minute. This page is the production story.

## Signal routing

| Signal | Backend | Transport |
|---|---|---|
| Metrics | Prometheus or Mimir | scrape `/telemetry/metrics` (preferred) or OTLP push via `telemetry:flush` |
| Traces | Tempo | OTLP `/v1/traces` at terminate |
| Logs & events | Loki | OTLP `/v1/logs` at terminate |

**Prefer scraping for metrics.** Pull is operationally simpler (no
scheduler dependency, Prometheus handles staleness) and the Redis store
means any node serves cluster-wide truth. Use OTLP metrics push only when
scraping isn't possible (serverless, locked-down networks).

## Self-hosted

Point OTLP at Tempo and Loki's OTLP receivers directly — no collector
needed. With both on one host, front them with a single Grafana Alloy
listening on 4318 and routing by signal:

```dotenv
TELEMETRY_EXPORTERS=otlp
TELEMETRY_OTLP_ENDPOINT=http://alloy.internal:4318
```

Prometheus scrapes each app node (or the VIP) per
[the Prometheus guide](prometheus.md).

## Grafana Cloud

One endpoint, basic auth:

```php
'otlp' => [
    'endpoint' => 'https://otlp-gateway-prod-eu-west-2.grafana.net/otlp',
    'headers' => [
        'Authorization' => 'Basic '.base64_encode(env('GRAFANA_INSTANCE_ID').':'.env('GRAFANA_CLOUD_TOKEN')),
    ],
],
```

The gateway routes traces→Tempo, logs→Loki, metrics→Mimir automatically.
For metrics, either enable the OTLP flush or run Alloy/Grafana Agent to
scrape `/telemetry/metrics` and remote-write to Mimir.

## Correlation — the payoff

Everything this package exports is built for Grafana's cross-linking:

- **Logs → traces**: log records carry `traceId`/`spanId`; configure the
  Loki data source's *derived field* on `traceId` to deep-link into Tempo.
- **Traces → logs**: Tempo's *trace to logs* jumps to Loki filtered by
  trace id and time range.
- **Metrics → traces**: dashboards share the `service.name` resource
  attribute across all three signals.

## Bundled dashboards — the Nightwatch-style suite

Thirteen service-scoped dashboards mirroring an APM sidebar, linked as
top-bar tabs with shared time range and filters: **Overview** (Activity /
Application / Drill-down sections, click-through tables), **Requests**,
**Jobs** (incl. queue wait time and worker leak curves), **Commands**,
**Scheduled Tasks**, **Exceptions**, **Queries** (slowest SQL, N+1
suspects), **Cache** (hit ratio + key-level spans), **Outgoing
Requests**, **Mail & Notifications**, **System**, **Users** and **Logs**.
Semantic colors throughout (green=ok, orange=retry/4xx, red=fail/5xx),
drill-down field links between dashboards, shared crosshair:

```bash
# straight into Grafana:
php artisan telemetry:dashboards --grafana=https://grafana.example.com --token=$TOKEN

# or export for file provisioning:
php artisan telemetry:dashboards --export=deploy/grafana/dashboards
```

Every panel filters on the `$service` variable, so one import serves all
apps shipping to the same stack. Datasource UIDs follow the
grafana/otel-lgtm convention (`prometheus`/`tempo`/`loki`); regenerate
after edits with `python3 resources/grafana/generate.py`.

Note: Grafana v13.0's anonymous mode currently fails to lazy-load panel
plugins (blank panels) — log in, or pin an older image, when using
anonymous access.

## Alerting

A bundled Prometheus rule file
(`resources/grafana/alerts/telemetry-alerts.yaml`) fires on the same
metrics the dashboards chart — request 5xx rate and p95 latency, exception
spikes, queue failures and backlog, scheduled-task failures, outgoing-HTTP
failures, and a pipeline self-check (export failing / no telemetry). Rules
are scoped per service + environment; thresholds are sensible defaults you
tune to your SLOs.

It's the standard Prometheus format, so it loads three ways:

```yaml
# Prometheus / Grafana Mimir — reference it from prometheus.yml:
rule_files:
  - /etc/prometheus/telemetry-alerts.yaml
```

Or in Grafana: **Alerting → Alert rules → Import** → Prometheus, and paste
the file. Route the `severity` label (`critical`/`warning`) to your
Alertmanager or Grafana contact points. Validate edits with
`promtool check rules resources/grafana/alerts/telemetry-alerts.yaml`.

## Query starters

```promql
# p95 request latency per route
histogram_quantile(0.95, sum by (le, http_route) (
  rate(http_server_request_duration_bucket[5m])))

# queue failure ratio
sum(rate(queue_jobs_failed_total[5m]))
  / sum(rate(queue_jobs_processed_total[5m]))

# host memory pressure (cboxdk/system-metrics)
system_memory_utilization{state="used"} > 0.9
```

```traceql
# slow checkout spans with errors
{ name =~ "POST /checkout.*" && duration > 500ms && status = error }
```

## Agent prompt

```text
Connect this Laravel app's cboxdk/laravel-telemetry to our Grafana stack:

1. Ask me which flavour: (a) Grafana Cloud, (b) self-hosted
   Tempo/Loki/Mimir behind one Alloy endpoint, or (c) self-hosted with
   separate endpoints.
2. Set TELEMETRY_EXPORTERS=otlp and OTEL_EXPORTER_OTLP_ENDPOINT. For
   Grafana Cloud, publish the config (artisan vendor:publish
   --tag=telemetry-config) and set otlp.headers Authorization to
   'Basic '.base64_encode(instanceId.':'.token) reading BOTH from env —
   never hardcode credentials.
3. Metrics: prefer Prometheus/Alloy scraping GET /telemetry/metrics
   (set TELEMETRY_ALLOWED_IPS accordingly). Only schedule
   telemetry:flush ->everyMinute()->onOneServer() if we push via OTLP.
4. Add the telemetry log channel to the logging stack so logs land in
   Loki trace-correlated.
5. Verify: php artisan telemetry:flush runs clean; php artisan about
   shows the exporter; traces appear in Tempo after hitting any route.
6. Summarize which signal goes where and what I must configure in
   Grafana (Loki derived field on traceId -> Tempo; Tempo trace-to-logs).
```

## Alternatives

Anything with an OTLP HTTP receiver works unchanged: Honeycomb, Datadog,
New Relic, Jaeger (traces), SigNoz, an OpenTelemetry Collector. The
Prometheus endpoint likewise feeds VictoriaMetrics or vanilla Prometheus.
Our dashboards and examples, however, assume the Grafana stack.

## Deploy annotations

Run `php artisan telemetry:deploy` from your deploy pipeline (Forge,
Envoyer, GitHub Actions) right after a release goes live. The emitted
`app.deployment` event lands in Loki, and every bundled dashboard
renders it as a purple vertical line — regressions map to deploys at a
glance. `--id=v1.2.3 --notes="hotfix"` overrides the auto-detected git
sha and adds a note. Every signal also carries `deployment.id` as a
resource attribute, so "which deploy is this trace from" is always
answerable.
