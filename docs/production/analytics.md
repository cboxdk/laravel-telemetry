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

Country is resolved at collection time so the raw IP can be dropped
afterwards, with a fixed precedence: a **hook** wins, then **Cloudflare's
`CF-IPCountry`** header, then an optional **MaxMind** database.

### Cloudflare (built-in, no database)

If you serve through Cloudflare, `client.geo.country` comes free on every
plan from the `CF-IPCountry` edge header — no MaxMind database to ship or
update, and no per-request lookup. Just enable geo:

```dotenv
TELEMETRY_ANALYTICS_GEO=true
# TELEMETRY_ANALYTICS_GEO_CF=true   # on by default
```

> **Trust your ingress, or this is spoofable.** `CF-*` headers are only
> trusted when the request arrives through a trusted proxy — otherwise anyone
> hitting your origin directly can send `CF-IPCountry: DK`. Configure Laravel's
> [`TrustProxies`](https://laravel.com/docs/requests#configuring-trusted-proxies)
> with **the immediate hop the app actually sees**: the Cloudflare ranges if
> CF connects to your app directly, or your **load balancer** in a
> `Cloudflare → LB → app` chain — after the LB, `REMOTE_ADDR` is the LB, so
> you trust the LB, *not* the CF ranges. Without trusted proxies the header is
> ignored — a safe no-op, not a hole. `XX` (unknown) and `T1` (Tor) are
> dropped, not stored as countries.
>
> Trusting the immediate hop is the same model as `X-Forwarded-For`: it proves
> the request came through your infra, not that Cloudflare set the header. Make
> sure your edge is the only ingress (the LB rejects non-Cloudflare traffic or
> strips inbound `CF-*`). For a topology-independent guarantee use
> [Authenticated Origin Pulls](https://developers.cloudflare.com/ssl/origin-configuration/authenticated-origin-pull/)
> or verify a CF-set secret in a `resolveClientGeoUsing()` hook.

This also covers the browser ingest endpoint: the browser can't know its own
country, but the ingest request is server-side and carries the visitor's
`CF-IPCountry`, so browser spans and events are stamped with geo at ingest —
no client cooperation, no database. The `User-Agent` is parsed server-side
the same way (`analytics.user_agent`), so nearly all enrichment happens in
one place.

### A custom edge or provider (hook)

Source `client.geo.*` from any header or logic — the hook always wins
([details](../extension-points/hooks.md#client-geo--resolveclientgeousing)):

```php
Telemetry::resolveClientGeoUsing(fn ($request) => array_filter([
    'client.geo.country' => $request->header('CF-IPCountry'),
    'client.geo.region'  => $request->header('CF-Region'),
]));
```

### MaxMind (built-in, no edge)

No edge geo? Turn on the built-in MaxMind resolver. Install the optional
package and point at a GeoLite2 database:

```bash
composer require geoip2/geoip2   # optional — a composer "suggest"
```

```dotenv
TELEMETRY_ANALYTICS_GEO=true
TELEMETRY_ANALYTICS_GEO_DB=/var/lib/GeoLite2-Country.mmdb
```

It resolves `client.geo.country` (+ continent) at collection time; the reader
is built lazily and cached (no boot-time I/O). Without the package or the
database it is a silent no-op.

## Device & browser

Turn on `TELEMETRY_ANALYTICS_UA` to parse `user_agent.original` into
low-cardinality `user_agent.name` / `os.name` / `device.type` (mobile /
tablet / desktop / bot) at collection time — dependency-free, families only
(never versions), so they stay safe group-by dimensions. Leave it off to keep
the raw UA for query-time parsing instead.

## Browser analytics

With `@telemetryBrowser` and analytics on (the directive emits
`data-analytics`), the [`@cboxdk/telemetry-browser`](https://github.com/cboxdk/telemetry-browser)
SDK adds — all as **events**, never sampled:

- **SPA page views** — `history` navigations the server never sees become
  `analytics.page_view` events (with `document.referrer` and the previous
  path). Full-page loads are still counted once by the server.
- **Engagement** — visible time (`visibilitychange`) and scroll depth,
  summarised into one `analytics.engagement` event on page hide, so you can
  compute bounce / engaged sessions.
- **Custom events / goals** — `telemetry.track('signup_completed', { plan })`
  for conversions.
- Device segmentation — screen size and `devicePixelRatio` alongside the
  existing viewport / language / connection dimensions.

The browser posts these to the same ingest endpoint under an `events` key;
the server re-emits them unsampled with the `analytics.source="browser"` and
`telemetry.stream="analytics"` markers, on the same stream as the server's
page views. Web Vitals continue to flow as browser spans, correlated by
`session.id`.

## On LGTM (low-traffic)

No ClickHouse needed for a small site: analytics events are **OTLP log
records**, so they land in **Loki** and answer the core questions with LogQL.
Select the stream with the marker, then aggregate:

```logql
# Views over time
sum(count_over_time({service_name="my-app"} | json | telemetry_stream="analytics" | analytics_event="page_view" [$__auto]))

# Top pages (last 24h)
topk(10, sum by (url_path) (count_over_time({service_name="my-app"} | json | analytics_event="page_view" [24h])))

# Top referrers
topk(10, sum by (http_request_header_referer) (count_over_time({service_name="my-app"} | json | analytics_event="page_view" [24h])))

# Approximate unique visitors (distinct session ids in the window)
count(sum by (session_id) (count_over_time({service_name="my-app"} | json | analytics_event="page_view" [24h])))
```

Uniques and funnels are **approximate** on Loki (it counts log lines, not a
`COUNT(DISTINCT …)` over a huge cardinality set). That is fine for a
low-traffic site. When you outgrow it, point an OTel Collector's ClickHouse
exporter at the same `telemetry.stream="analytics"` events for exact
uniques/funnels — **no application change**, the events are identical.

## Privacy

Cookieless by default; the raw IP is never a grouping key and can be dropped
once geo is resolved server-side. Provide a user **id** only, never a name or
email. Consent-gated and cookie-based modes are possible through the session
hook.
