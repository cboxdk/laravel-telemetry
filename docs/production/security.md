---
title: Security
description: Keeping telemetry from leaking what it shouldn't
weight: 4
---

# Security

## The scrape endpoint

- Restrict with `TELEMETRY_ALLOWED_IPS` (CIDR supported) or replace the
  middleware stack per endpoint with your own auth.
- Use `only` filters to publish a minimal metric set on any endpoint that
  is reachable from less-trusted networks.
- Set `TELEMETRY_PROMETHEUS_ENABLED=false` if you export via OTLP only —
  the routes are never registered.

## What leaves the app

- **Metric names/labels** describe your system's shape. Keep label values
  bounded and non-personal (no emails, user ids, tokens).
- **Query spans** include SQL text truncated to 500 characters — bindings
  are *not* included, so values stay out of traces by default.
- **Exception events** on spans carry the message and stack trace. If your
  exception messages can contain user data, sanitize at the source.
- **Events** contain exactly the attributes you pass. Treat
  `Telemetry::event()` payloads like log lines: no secrets.

## Incoming trace headers

`traces.continue_incoming` trusts `traceparent` from clients, which lets
callers set your trace ids and sampling decision. That's standard W3C
behaviour and safe for internal meshes; disable it on public edges if you
don't want clients influencing sampling:

```dotenv
TELEMETRY_TRACES_CONTINUE_INCOMING=false
```

## Transport

OTLP export honours HTTPS endpoints and custom auth headers. Credentials
live in env/config — never in metric names or attributes.
