---
title: Analytics
description: Observability-grade web analytics on the telemetry you already collect
weight: 8
---

# Analytics

An additive analytics layer on top of the telemetry the package already
collects — visits, top pages, referrers, geo, Web Vitals — stronger than
classic web analytics because it owns **both** the client and server side of
every request and stitches them on one `trace_id` / `session.id`.

It is **opt-in and off by default**. With `telemetry.analytics.enabled`
false, nothing here runs and emitted telemetry is byte-for-byte unchanged.
It emits standard OTLP, so it stays sink-agnostic: a low-traffic self-hosted
LGTM stack answers top-pages / views / referrers approximately, while a
high-traffic deployment can point an OTel→ClickHouse exporter at the same
event stream for exact uniques and funnels — no application change.

```dotenv
TELEMETRY_ANALYTICS=true
TELEMETRY_ANALYTICS_SALT=a-long-random-secret   # for the cookieless hash
```

## The `session.id` keystone

A single `session.id` is shared across the browser and the server, so a
whole **visit** — not just one page load's trace — is one key. The server
middleware stamps it on the request span, and the `@telemetryBrowser`
directive propagates the same value to the RUM SDK, which stamps it on every
browser span.

The built-in default is **cookieless**: a daily-rotating, salted hash of
IP + user agent + host + day (the "Fathom trick"), so a raw IP is never a
durable grouping key and the value rotates every midnight.

Override it — from Cloudflare, a first-party cookie, or your own logic — with
a [hook](../extension-points/hooks.md#analytics-session-id--resolvesessionusing):

```php
Telemetry::resolveSessionUsing(fn ($request) =>
    $request->header('CF-Ray') ?: $request->cookie('visit'));
```

## Page views — unsampled by design

Each top-level document load (a GET returning HTML, non-AJAX) emits an
`analytics.page_view` **event** — an OTLP log record, *not* a span. Events
bypass trace sampling entirely, so a page view is **never undercounted**,
even when the full trace is tail-sampled away. It still carries the
`trace_id` and `session.id`, so you can always drill from a view to its
(maybe partial) waterfall.

Each event is a flat, one-row-per-view shape: `session.id`, `url.path`,
`http.route`, `http.response.status_code`, `user_agent.original`, the
referrer, `enduser.id`, `client.geo.*`, plus a `telemetry.stream="analytics"`
marker so an OTel Collector can route it to ClickHouse without any app
change. Disable with `TELEMETRY_ANALYTICS_PAGE_VIEWS=false`.

## Geo, without a database

Supply `client.geo.*` straight from your edge (e.g. Cloudflare), so no geo
database is needed and the raw IP can be dropped
([hook](../extension-points/hooks.md#client-geo--resolveclientgeousing)):

```php
Telemetry::resolveClientGeoUsing(fn ($request) => array_filter([
    'client.geo.country' => $request->header('CF-IPCountry'),
]));
```

## Privacy

Cookieless by default; the raw IP is never a grouping key and can be dropped
once geo is resolved server-side. Provide a user **id** only, never a name or
email. Consent-gated and cookie-based modes are possible through the session
hook.
