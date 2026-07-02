# Laravel Telemetry

[![Latest Version on Packagist](https://img.shields.io/packagist/v/cboxdk/laravel-telemetry.svg?style=flat-square)](https://packagist.org/packages/cboxdk/laravel-telemetry)
[![Tests](https://img.shields.io/github/actions/workflow/status/cboxdk/laravel-telemetry/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/cboxdk/laravel-telemetry/actions/workflows/run-tests.yml)
[![PHPStan](https://img.shields.io/github/actions/workflow/status/cboxdk/laravel-telemetry/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/cboxdk/laravel-telemetry/actions/workflows/phpstan.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/cboxdk/laravel-telemetry.svg?style=flat-square)](https://packagist.org/packages/cboxdk/laravel-telemetry)

**Collector-free telemetry for Laravel: Prometheus metrics, OTLP traces and
events. No C extension, no protobuf, no sidecar — metrics that actually work
under FPM.**

```php
Telemetry::counter('orders.created')->inc();
Telemetry::gauge('queue.depth', fn () => Queue::size());
Telemetry::histogram('checkout.duration', unit: 'ms')->record($ms);

Telemetry::span('import.customers', function () {
    // traced work — exceptions recorded, duration measured
});

Telemetry::event('autoscale.decision', ['workers' => 7]);
```

## Why

PHP is shared-nothing: in-process metric state dies with the request, which
is why the official OTel SDK's metrics don't work under FPM without a
collector. This package aggregates metrics in **Redis** (or APCu) instead —
web workers, queue workers and nodes all write to the same series. Prometheus
scrapes a route; traces and events go straight to any OTLP backend as
spec-stable HTTP JSON.

- ✅ Pure Composer package — deploys anywhere Laravel runs
- ✅ Prometheus scrape endpoint(s) with IP allowlisting and metric filters
- ✅ Real OTLP (`/v1/traces`, `/v1/metrics`, `/v1/logs`) without the SDK
- ✅ `telemetry` log channel: Laravel logs become trace-correlated OTLP log
  records (severity-mapped, feedback-loop safe)
- ✅ Auto-instrumentation: requests, queue jobs (full W3C trace propagation
  into workers), DB queries, commands — plus `Http::withTraceparent()` for
  outbound calls
- ✅ `Telemetry::fake()` with assertions for counters, gauges, histograms,
  spans and events
- ✅ Provider contract so packages publish telemetry without coupling
- ✅ Host CPU/memory/load via [`cboxdk/system-metrics`](https://github.com/cboxdk/system-metrics) — just install it
- ✅ Zero cost when disabled; telemetry never throws into your app
- ✅ AI-ready: ships [Laravel Boost](https://github.com/laravel/boost)
  guidelines, `llms.txt` and an agent guide — your AI assistant follows the
  conventions out of the box

## Installation

```bash
composer require cboxdk/laravel-telemetry
```

```dotenv
TELEMETRY_STORE=redis            # redis | apcu | array
TELEMETRY_EXPORTERS=otlp         # optional; Prometheus endpoint is on by default
OTEL_EXPORTER_OTLP_ENDPOINT=https://otlp.example.com:4318
```

Scrape `GET /telemetry/metrics`, and for OTLP metrics schedule the flush:

```php
Schedule::command('telemetry:flush')->everyMinute()->onOneServer();
```

## Documentation

Full documentation lives in [`docs/`](docs/index.md):

- [Getting started](docs/getting-started/installation.md) —
  [quickstart](docs/getting-started/quickstart.md),
  [testing](docs/getting-started/testing.md),
  [API reference](docs/getting-started/api-reference.md),
  [AI assistants](docs/getting-started/ai-assistants.md)
- [Core concepts](docs/core-concepts/architecture.md) —
  [metrics](docs/core-concepts/metrics.md),
  [traces](docs/core-concepts/traces.md),
  [events](docs/core-concepts/events.md),
  [logs](docs/core-concepts/logs.md),
  [naming](docs/core-concepts/naming.md)
- Cookbook —
  [multi-tenant SaaS](docs/cookbook/multi-tenant-saas.md),
  [queue monitoring](docs/cookbook/queue-monitoring.md),
  [external services](docs/cookbook/external-services.md)
- [Configuration reference](docs/configuration/reference.md)
- [Extension points](docs/extension-points/providers.md) —
  [custom exporters](docs/extension-points/exporters.md)
- [Production](docs/production/prometheus.md) —
  [OTLP](docs/production/otlp.md),
  [**the recommended Grafana stack**](docs/production/grafana-stack.md),
  [performance](docs/production/performance.md),
  [security](docs/production/security.md)

Design decisions (and the prior-art survey behind them) are recorded in
[`docs/adr/`](docs/adr/0001-runtime-model.md).

## Publishing telemetry from your package

```php
if (class_exists(\Cbox\Telemetry\Facades\Telemetry::class)) {
    Telemetry::provider(new QueueMetricsProvider);
}
```

Telemetry never knows about your package — your package publishes telemetry
if telemetry exists. See [providers](docs/extension-points/providers.md).

## Development

```bash
composer check   # pint --test, phpstan (level 8), pest
```

## License

MIT. See [LICENSE.md](LICENSE.md).
