---
title: Security
description: Keeping telemetry from leaking what it shouldn't
weight: 5
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
- **Request spans** capture only allowlisted headers
  (`instrument.request_headers` / `response_headers`); credential and
  session headers (`Authorization`, `Cookie`, `X-Api-Key`, …) are
  denylisted and never captured, even when allowlisted. `url.query`
  redacts common secret parameters (`token`, `signature`, `code`, …).

## The redaction engine

Everything above is capture-side hygiene. On top of it, every span
attribute, span event (exception messages!) and telemetry event passes
through the redaction engine at flush time — the last hands before any
exporter:

- **Key-based**: attribute keys whose dot/underscore segments match a
  sensitive word (`password`, `api_key`, `authorization`, …) have their
  value replaced entirely. Segment matching, not substring — `cache.key`
  is safe, `stripe.api_key` is caught.
- **Pattern-based**: regexes scrub secrets *embedded* in any string
  value — JWTs, `Bearer`/`Basic` credentials and url userinfo
  (`redis://user:pass@host`) by default — wherever they appear:
  exception messages, event payloads, log records.
- The engine covers **log records** too — the message itself (key
  `log.message`) and its context attributes are scrubbed like everything
  else.
- **Custom hook**, run last:

```php
Telemetry::redactUsing(function (string $key, string $value): ?string {
    return preg_match('/\d{6}-\d{4}/', $value) ? '[CPR]' : null; // null = keep
});
```

Configure under `telemetry.redaction` (`keys`, `patterns`,
`replacement`, `enabled`) — extend the defaults via
`Redactor::defaultKeys()` / `defaultPatterns()`. A broken pattern or
hook never breaks telemetry; it is skipped. Metric *labels* are not run
through the engine — they are bounded dimensions by design and must
never contain user data in the first place.

## Incoming trace headers

`traces.continue_incoming` trusts `traceparent` from clients, which lets
callers set your trace ids and sampling decision. That's standard W3C
behaviour and safe for internal meshes. On public edges you have two
knobs:

```dotenv
# Keep trace-id correlation but decide sampling locally —
# clients can no longer force sampling on (bypassing your rate) or off:
TELEMETRY_TRACES_TRUST_INCOMING_SAMPLING=false

# Or ignore incoming trace context entirely:
TELEMETRY_TRACES_CONTINUE_INCOMING=false
```

## Transport

OTLP export honours HTTPS endpoints and custom auth headers. Credentials
live in env/config — never in metric names or attributes.
