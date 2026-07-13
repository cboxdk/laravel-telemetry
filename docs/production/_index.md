---
title: Production
description: Production documentation.
weight: 1
---

# Production

- **[Prometheus](prometheus.md)** — Scrape setup, endpoint security and store hygiene
- **[OTLP](otlp.md)** — Direct OTLP/HTTP export to any OpenTelemetry backend
- **[Grafana stack](grafana-stack.md)** — Our recommended backend — Prometheus/Mimir, Tempo, Loki, one Grafana
- **[Performance](performance.md)** — What telemetry costs and how to tune it
- **[Security](security.md)** — Keeping telemetry from leaking what it shouldn't
- **[Error tracking & support flow](error-tracking.md)** — Correlate Sentry/Flare issues, support cases and traces via the trace id
- **[Browser tracing (RUM)](browser-tracing.md)** — Optional frontend span ingest — end-to-end distributed tracing from the browser through your backend
- **[Analytics](analytics.md)** — Observability-grade web analytics on the telemetry you already collect
