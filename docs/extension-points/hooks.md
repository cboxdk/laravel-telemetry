---
title: Runtime hooks
description: Resolvers that packages and apps register to shape what gets recorded
weight: 3
---

# Runtime hooks

Beyond [providers](providers.md) (publish metrics) and
[exporters](exporters.md) (ship signals), a set of resolver hooks lets an
app — or a package building on top, like a CMS integration — shape what
the built-in instrumentation records. All of them are guarded: a throwing
resolver is reported and ignored, never breaking the request.

## Request span naming — `nameRequestsUsing()`

Essential behind **catch-all routes** (Statamic, wildcard APIs), where the
route pattern names every request identically:

```php
Telemetry::nameRequestsUsing(function ($request, $response) {
    $entry = $request->attributes->get('resolved.entry');

    return $entry ? 'GET entry:'.$entry->collection : null; // null = default name
});
```

Keep names **bounded** — collections and types, never ids or slugs.
Precedence: an explicit `updateName()` on the span during the request
always wins; then this resolver; then the default `METHOD <route>`.

`nameRequestsUsing` shapes only the span *name*. To also fix the
`http.route` metric label (so dashboards and route tables group by the
logical route, not the catch-all), pair it with `resolveRouteUsing()`.

## Logical route — `resolveRouteUsing()`

For a catch-all framework the literal route template is useless — a CMS's
single `/{segments?}` is the `http.route` on every page, so every route
table and latency histogram collapses into one bucket. This hook supplies
the **logical route**, which replaces `http.route` on both the span
attribute *and* the metric label. Everything downstream — the UI route
table, Grafana, TraceQL — then groups by it:

```php
Telemetry::resolveRouteUsing(function ($request, $response) {
    $entry = $request->attributes->get('resolved.entry');

    return $entry ? 'entry:'.$entry->collection : null; // null = keep the template
});
```

The return value **MUST be bounded** — it is a metric label, so a fixed,
small set (content types, collections), never an id or slug. When it
overrides the template, the raw pattern is preserved as the
`http.route.template` span attribute. This is the route counterpart to
`nameRequestsUsing`; a catch-all instrumentation usually sets both (often
to the same value — the name is `METHOD ` + route).

## Root-span enrichment — `enrichRequestsUsing()`

Extra attributes on the request root span at terminate, with the final
response in hand (status-dependent enrichment works):

```php
Telemetry::enrichRequestsUsing(fn ($request, $response) => [
    'app.static_cache' => $response->headers->get('X-Cache', 'miss'),
]);
```

Runs before the tail-detail decision and the redaction engine. For
*metric labels* use `labelRequestsUsing()` instead — attributes are
per-span (unbounded ok), labels multiply cardinality (bounded only).

## Cache key classification — `classifyCacheKeysUsing()`

Cache-heavy subsystems (a CMS content cache, an ORM cache) produce
thousands of raw keys. Classify them into bounded groups — or drop them:

```php
Telemetry::classifyCacheKeysUsing(function (string $store, string $key) {
    if (str_starts_with($key, 'stache::indexes::')) {
        return 'stache.index';
    }

    return str_starts_with($key, 'internal:') ? null : 'app'; // null = drop
});
```

With a classifier registered, kept operations carry the group as a
`key_group` counter label and a `cache.key.group` span attribute (the raw
key stays on the span). Whole stores can be excluded with
`instrument.cache_ignore_stores`.

## Analytics session id — `resolveSessionUsing()`

Only active when `telemetry.analytics.enabled` is on. Overrides how the
shared `session.id` (the analytics keystone — one visit key across browser
and server spans) is derived from the request. The built-in default is a
cookieless, daily-rotating salted hash; a hook lets you source it from
Cloudflare, a first-party cookie, or your own logic:

```php
Telemetry::resolveSessionUsing(fn ($request) =>
    $request->header('CF-Ray')          // Cloudflare's request id
        ?: $request->cookie('visit'));  // or your own cookie
```

Return `null` to fall back to the cookieless default. Whatever it returns
is also propagated to the browser (via the `@telemetryBrowser` directive's
`data-session`), so the RUM SDK stamps the SAME `session.id`.

## Client geo — `resolveClientGeoUsing()`

Only active when `telemetry.analytics.enabled` is on. Supplies
`client.geo.*` (and may override `client.address`) for the request span —
e.g. straight from Cloudflare's edge headers, so no geo database is needed
and the raw IP can be dropped:

```php
Telemetry::resolveClientGeoUsing(fn ($request) => array_filter([
    'client.geo.country' => $request->header('CF-IPCountry'),
    'client.geo.region'  => $request->header('CF-Region'),
    'client.geo.city'    => $request->header('CF-IPCity'),
]));
```

## The full hook surface

| Hook | Shapes | Signature |
|---|---|---|
| `nameRequestsUsing()` | root span name | `fn ($request, $response): ?string` |
| `resolveRouteUsing()` | `http.route` (span + metric, bounded!) | `fn ($request, $response): ?string` |
| `enrichRequestsUsing()` | root span attributes | `fn ($request, $response): array` |
| `labelRequestsUsing()` | request metric labels (bounded!) | `fn ($request): array` |
| `resolveUserUsing()` | user attribution | `fn ($user, ?string $guard): array` |
| `classifyCacheKeysUsing()` | cache grouping/dropping | `fn (string $store, string $key): ?string` |
| `redactUsing()` | last-pass redaction | `fn (string $key, string $value): ?string` |
| `handleExceptionsUsing()` | internal-failure reporting | `fn (Throwable $e): void` |
| `Telemetry::context()` | ambient dimensions on all signals | — |
| `Tracer::recordSpan()` / `bumpStat()` / `rootSpan()` | custom spans, backdated spans, root tallies | — |
| `Telemetry::contributes()` | conditional registration when telemetry exists | — |

A package integration typically combines these: a user resolver, a
`context()` listener for its ambient dimensions (site, tenant), a request
namer for its routing model, a cache classifier for its cache traffic,
and a `TelemetryProvider` for its gauges.
