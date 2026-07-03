---
title: Installation
description: Install and configure cboxdk/laravel-telemetry
weight: 1
---

# Installation

```bash
composer require cboxdk/laravel-telemetry
```

The service provider and the `Telemetry` facade are auto-discovered.

Publish the config when you need to change defaults:

```bash
php artisan vendor:publish --tag=telemetry-config
```

## Choose a metric store

Push instruments (counters, push gauges, histograms) need shared storage so
values survive requests and aggregate across processes:

| Store   | When                                            |
|---------|-------------------------------------------------|
| `redis` | Default. Multi-node, web + queue workers.       |
| `apcu`  | Single node without Redis (`ext-apcu` required; `apc.enable_cli=1` for workers). |
| `array` | Tests and local experiments (per-process only). |

```dotenv
TELEMETRY_STORE=redis
TELEMETRY_REDIS_CONNECTION=default
```

We recommend pointing telemetry at a Redis connection separate from your
queue connection. Observable gauges (callback-based) never touch the store.

## Enable an exporter

Prometheus is on by default — `/telemetry/metrics` renders the store at
scrape time. For OTLP:

```dotenv
TELEMETRY_EXPORTERS=otlp
TELEMETRY_OTLP_ENDPOINT=https://otel.example.com:4318
```

Spans and events export at request/job terminate. OTLP **metrics** are
pushed by the scheduler — add to `routes/console.php`:

```php
Schedule::command('telemetry:flush')->everyMinute();
```

## System metrics (optional)

```bash
composer require cboxdk/system-metrics
```

That's it — host memory, CPU and load metrics (`system.*`) appear on the
next scrape. No agent, no node_exporter.

## Disable everything

```dotenv
TELEMETRY_ENABLED=false
```

Instruments become no-ops, no listeners are registered, routes disappear.

## Verify the setup

```bash
php artisan telemetry:doctor
```

Checks the store round trip, exporter reachability and warns about an
unprotected scrape endpoint. Run it after install and from deploy
pipelines.

## Agent prompt

Paste this into your AI assistant (Claude Code, Cursor, Copilot) to have
it perform the installation:

```text
Install and configure cboxdk/laravel-telemetry in this Laravel app:

1. composer require cboxdk/laravel-telemetry
2. Pick the metric store: set TELEMETRY_STORE=redis in .env if the app has
   Redis configured (check config/database.php); otherwise use apcu when
   ext-apcu is available, else ask me. If Redis is shared with the queue,
   add a dedicated `telemetry` connection in config/database.php and set
   TELEMETRY_REDIS_CONNECTION=telemetry.
3. If we export to an OTLP backend (Tempo/Grafana/Honeycomb), set
   TELEMETRY_EXPORTERS=otlp and OTEL_EXPORTER_OTLP_ENDPOINT, and register
   Schedule::command('telemetry:flush')->everyMinute()->onOneServer() in
   routes/console.php. Skip this if we only scrape Prometheus.
4. Protect the Prometheus endpoint: set TELEMETRY_ALLOWED_IPS to our
   monitoring CIDR, or disable it with TELEMETRY_PROMETHEUS_ENABLED=false
   if unused.
5. Add the telemetry log channel to the stack in config/logging.php:
   'telemetry' => ['driver' => 'telemetry', 'level' => 'info'] and append
   'telemetry' to the stack channel list.
6. Verify: php artisan telemetry:doctor must pass all checks, and a
   request to /telemetry/metrics must return Prometheus text.
7. Do NOT publish the config unless we need non-default endpoints.

Conventions and API: read vendor/cboxdk/laravel-telemetry/llms.txt and
vendor/cboxdk/laravel-telemetry/docs/getting-started/api-reference.md.
```
