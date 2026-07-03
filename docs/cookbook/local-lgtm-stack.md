---
title: Local LGTM stack (dev / test / CI)
description: Spin up Grafana + Tempo + Loki + Prometheus in one container and see your first trace in a minute
weight: 4
---

# A local LGTM stack in one container

You don't need Grafana Cloud or a collector to see this package working.
`grafana/otel-lgtm` is an all-in-one image: one OTLP endpoint on `4318`
fanning out to **T**empo (traces), **L**oki (logs) and Prometheus
(**M**etrics), with a pre-provisioned **G**rafana on
`http://localhost:3000`. This is exactly what the package is developed
against.

## Fastest path — one command

```bash
docker run --rm --name lgtm \
  -p 3000:3000 -p 4318:4318 -p 9090:9090 -p 3200:3200 -p 3100:3100 \
  grafana/otel-lgtm:latest
```

Wait for `The OpenTelemetry collector and the Grafana LGTM stack are up
and running` in the logs, then point your app at it:

```dotenv
TELEMETRY_ENABLED=true
TELEMETRY_STORE=redis              # or "apcu" / "array" for a quick local run
TELEMETRY_EXPORTERS=otlp
TELEMETRY_OTLP_ENDPOINT=http://localhost:4318

# See detail spans (queries, cache, views) on every request while developing:
TELEMETRY_TRACES_DETAILS=always
```

Hit any route, then open Grafana → **Explore** → **Tempo** → *Search*.
Your request is there — click it for the waterfall. That's the whole
loop, no collector, no signup.

## Persistent stack — docker-compose

For day-to-day dev, a compose file survives restarts and keeps your
dashboards and data:

```yaml
# docker-compose.yml
services:
  lgtm:
    image: grafana/otel-lgtm:latest
    container_name: lgtm
    ports:
      - "3000:3000"   # Grafana UI
      - "4318:4318"   # OTLP HTTP — the package posts here
      - "9090:9090"   # Prometheus query API
      - "3200:3200"   # Tempo query API
      - "3100:3100"   # Loki query API
    volumes:
      # Everything persists under /data — Prometheus, Tempo, Loki AND
      # Grafana's own db (dashboards, datasources). One volume is enough.
      - lgtm-data:/data
    environment:
      - GF_AUTH_ANONYMOUS_ENABLED=true
      - GF_AUTH_ANONYMOUS_ORG_ROLE=Admin

volumes:
  lgtm-data:
```

```bash
docker compose up -d
docker compose logs -f lgtm      # wait for "up and running"
```

## Load the bundled dashboards

The package ships the full Nightwatch-style dashboard suite. Push it into
your local Grafana with one command:

```bash
php artisan telemetry:dashboards --grafana=http://admin:admin@localhost:3000
```

They appear under the **Telemetry** dropdown (a tab bar linking all 13
dashboards). Metrics need scraping or the OTLP flush — for a local run
the OTLP push is simplest:

```bash
php artisan telemetry:flush        # once, or on a schedule / --daemon
```

> **Grafana v13 anonymous-mode caveat.** Some `grafana/otel-lgtm` builds
> ship Grafana 13.x, whose anonymous mode fails to lazy-load panel
> plugins — every panel (including Grafana's own) renders blank with
> "Loading plugin panel…". It is **not** the dashboard JSON. Log in at
> `http://localhost:3000` with `admin` / `admin` and the panels render.
> The `telemetry:dashboards` importer already authenticates, so import
> works regardless.

## Verify the whole loop from the shell

```bash
# 1. Make a request and grab the trace id the package returns.
TID=$(curl -si http://localhost:8000/ | awk 'tolower($1)=="x-trace-id:"{print $2}' | tr -d '\r')

# 2. Look it up in Tempo (give it a few seconds to ingest).
sleep 5
curl -s "http://localhost:3000/api/datasources/proxy/uid/tempo/api/traces/$TID" \
  -u admin:admin | jq '.batches[].scopeSpans[].spans[].name'

# 3. Confirm metrics landed in Prometheus.
curl -s "http://localhost:3000/api/datasources/proxy/uid/prometheus/api/v1/label/__name__/values" \
  -u admin:admin | jq '.data[] | select(startswith("http_server"))'
```

## In CI — an ephemeral stack for integration tests

The `--group=redis` / OTLP integration tests need real backing services.
Stand the stack up as a GitHub Actions service and tear it down with the
job:

```yaml
jobs:
  integration:
    runs-on: ubuntu-latest
    services:
      redis:
        image: redis:7
        ports: ["6379:6379"]
      lgtm:
        image: grafana/otel-lgtm:latest
        ports: ["4318:4318", "3000:3000"]
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: "8.3" }
      - run: composer install --no-interaction
      - run: vendor/bin/pest --group=redis
        env:
          OTEL_EXPORTER_OTLP_ENDPOINT: http://localhost:4318
          TELEMETRY_STORE: redis
```

For pure unit tests you need none of this — `TELEMETRY_STORE=array` plus
`Telemetry::fake()` covers assertions without any container.

## Tear down

```bash
docker compose down            # keep volumes
docker compose down -v         # wipe metrics/traces/logs/dashboards too
# one-liner variant: just Ctrl-C (it was --rm)
```

## Where to go next

- Production backends (self-hosted, Grafana Cloud, scrape vs push):
  [The Grafana stack](../production/grafana-stack.md).
- Wire it into your own app with the [quickstart](../getting-started/quickstart.md),
  then drive traffic through your routes, queues and commands to see every
  signal type land.
