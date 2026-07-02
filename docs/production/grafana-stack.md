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
OTEL_EXPORTER_OTLP_ENDPOINT=http://alloy.internal:4318
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

## Alternatives

Anything with an OTLP HTTP receiver works unchanged: Honeycomb, Datadog,
New Relic, Jaeger (traces), SigNoz, an OpenTelemetry Collector. The
Prometheus endpoint likewise feeds VictoriaMetrics or vanilla Prometheus.
Our dashboards and examples, however, assume the Grafana stack.
