---
title: Configuration reference
description: Every config key in config/telemetry.php
weight: 1
---

# Configuration reference

Publish with `php artisan vendor:publish --tag=telemetry-config`.

## Master switch

| Key | Env | Default |
|---|---|---|
| `enabled` | `TELEMETRY_ENABLED` | `true` |

Disabled: no-op instruments, no listeners, no routes, no providers.

## Service resource

Attached to every exported signal (OTel resource conventions).

| Key | Env | Default |
|---|---|---|
| `service.name` | `TELEMETRY_SERVICE_NAME` | `APP_NAME` |
| `service.namespace` | `TELEMETRY_SERVICE_NAMESPACE` | — |
| `service.version` | `TELEMETRY_SERVICE_VERSION` | — |
| `service.environment` | `TELEMETRY_ENVIRONMENT` | `APP_ENV` |

## Metric store

| Key | Env | Default |
|---|---|---|
| `store` | `TELEMETRY_STORE` | `redis` |
| `stores.redis.connection` | `TELEMETRY_REDIS_CONNECTION` | `default` |
| `stores.redis.prefix` | `TELEMETRY_REDIS_PREFIX` | `telemetry` |
| `stores.apcu.prefix` | `TELEMETRY_APCU_PREFIX` | `telemetry` |

## Exporters

| Key | Env | Default |
|---|---|---|
| `exporters` | `TELEMETRY_EXPORTERS` (comma-separated) | `[]` |

Valid entries: `otlp`, `null`, or a fully-qualified class name implementing
`Cbox\Telemetry\Contracts\Exporter` (resolved from the container).

### OTLP

| Key | Env | Default |
|---|---|---|
| `otlp.endpoint` | `OTEL_EXPORTER_OTLP_ENDPOINT` | `http://localhost:4318` |
| `otlp.headers` | — | `[]` |
| `otlp.timeout` | `TELEMETRY_OTLP_TIMEOUT` | `3.0` s |
| `otlp.connect_timeout` | `TELEMETRY_OTLP_CONNECT_TIMEOUT` | `1.0` s |

### Prometheus

| Key | Env | Default |
|---|---|---|
| `prometheus.enabled` | `TELEMETRY_PROMETHEUS_ENABLED` | `true` |
| `prometheus.endpoints.*.path` | `TELEMETRY_PROMETHEUS_PATH` | `telemetry/metrics` |
| `prometheus.endpoints.*.middleware` | — | `[AllowIps::class]` |
| `prometheus.endpoints.*.only` | — | `null` (all metrics) |
| `prometheus.allowed_ips` | `TELEMETRY_ALLOWED_IPS` (comma-separated, CIDR ok) | `[]` (allow all) |

Multiple named endpoints are supported — e.g. an `internal` endpoint with
everything and a `public` endpoint filtered to a prefix list:

```php
'endpoints' => [
    'internal' => ['path' => 'internal/metrics', 'middleware' => [AllowIps::class]],
    'public' => ['path' => 'metrics', 'middleware' => ['auth:metrics'], 'only' => ['http', 'queue']],
],
```

## Traces

| Key | Env | Default |
|---|---|---|
| `traces.sample_rate` | `TELEMETRY_TRACES_SAMPLE_RATE` | `1.0` |
| `traces.max_buffer` | `TELEMETRY_TRACES_MAX_BUFFER` | `5000` |
| `traces.continue_incoming` | `TELEMETRY_TRACES_CONTINUE_INCOMING` | `true` |

## Histograms

| Key | Default |
|---|---|
| `default_buckets` | `[1, 5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000]` (ms) |

## Automatic instrumentation

| Key | Env | Default |
|---|---|---|
| `instrument.requests` | `TELEMETRY_INSTRUMENT_REQUESTS` | `true` |
| `instrument.jobs` | `TELEMETRY_INSTRUMENT_JOBS` | `true` |
| `instrument.queries` | `TELEMETRY_INSTRUMENT_QUERIES` | `true` |
| `instrument.commands` | `TELEMETRY_INSTRUMENT_COMMANDS` | `false` |
| `queue.propagate` | `TELEMETRY_QUEUE_PROPAGATE` | `true` |

## Built-in providers

| Key | Env | Default |
|---|---|---|
| `providers.system.enabled` | `TELEMETRY_SYSTEM_METRICS` | `true` |
| `providers.system.cpu_interval` | `TELEMETRY_SYSTEM_CPU_INTERVAL` | `0.1` s (`0` disables CPU utilization) |

The system provider only activates when `cboxdk/system-metrics` is
installed.
