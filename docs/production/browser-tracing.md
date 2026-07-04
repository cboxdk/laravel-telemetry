---
title: Browser tracing (RUM)
description: Optional frontend span ingest — end-to-end distributed tracing from the browser through your backend
weight: 7
---

# Browser tracing — end-to-end distributed tracing

The backend already **continues an incoming W3C `traceparent`**, so if the
browser propagates its trace context on `fetch`/XHR, your backend spans are
already children of the browser's trace. What was missing is a place for the
browser to ship *its own* spans (page load, resource timings, JS errors).

This package can be that place — the Laravel app is the collector for its
own frontend. Enable the optional ingest endpoint and browser + backend
spans land in Tempo under **one trace id**: click → browser span →
request span → queries → jobs, all in a single waterfall.

## Enable it

```dotenv
TELEMETRY_INGEST_SPANS=true
# TELEMETRY_INGEST_SPANS_PATH=telemetry/spans   # default
```

The endpoint is **off by default** and, when on, protected by throttling,
strict payload bounding and optional head sampling — never a bearer token
(a browser can't hold a secret). Tune it in `config/telemetry.php`:

```php
'ingest' => ['spans' => [
    'enabled' => env('TELEMETRY_INGEST_SPANS', false),
    'path' => 'telemetry/spans',
    'middleware' => ['throttle:300,1'],   // add 'auth' etc. if the app is behind login
    'max_spans' => 128,                    // per batch; excess dropped
    'max_attributes' => 32,                // per span
    'sample_rate' => 1.0,                  // head sampling (0–1) to cap volume
]],
```

## Correlate the browser to the server trace

Drop the directive in your layout `<head>` — it renders a
`<meta name="traceparent">` with the current server trace (a no-op when
none is active):

```blade
<head>
    @telemetryTraceparent
    ...
</head>
```

The browser reads that as the parent for its root span, and sends the same
`traceparent` header on API calls so the backend continues it.

## The endpoint contract

`POST {path}` with `Content-Type: application/json`:

```json
{
  "spans": [
    {
      "traceId": "0af7651916cd43dd8448eb211c80319c",
      "spanId": "b7ad6b7169203331",
      "parentSpanId": "0020000000000001",
      "name": "document.load",
      "kind": "client",
      "start": 1720000000000,
      "end": 1720000000420,
      "attributes": { "http.url": "https://app.test/dashboard" },
      "status": "ok"
    }
  ]
}
```

- `traceId` (32 hex) and `spanId` (16 hex) are required; `parentSpanId`
  optional. `start`/`end` are epoch **milliseconds** (JS-friendly).
- Invalid spans are dropped (never fatal); every value passes the
  [redaction engine](security.md#the-redaction-engine) before export, and
  each span is stamped `browser: true`.
- Always returns `204`.

## A minimal browser snippet

No SDK required — a few lines get you page-load + fetch spans:

```js
const hex = n => [...crypto.getRandomValues(new Uint8Array(n))]
  .map(b => b.toString(16).padStart(2, '0')).join('');

// Root the browser trace on the server trace when present.
const server = document.querySelector('meta[name=traceparent]')?.content;
const traceId = server ? server.split('-')[1] : hex(16);
const rootId = server ? server.split('-')[2] : hex(8);

addEventListener('load', () => {
  const nav = performance.getEntriesByType('navigation')[0];
  const t0 = performance.timeOrigin;
  navigator.sendBeacon('/telemetry/spans', JSON.stringify({ spans: [{
    traceId, spanId: hex(8), parentSpanId: rootId,
    name: 'document.load', kind: 'client',
    start: t0 + nav.startTime, end: t0 + nav.loadEventEnd,
    attributes: { 'http.url': location.href },
  }] }));
});

// Propagate to the backend so its spans join this trace.
const origFetch = window.fetch;
window.fetch = (input, init = {}) => {
  const spanId = hex(8);
  init.headers = { ...(init.headers || {}), traceparent: `00-${traceId}-${spanId}-01` };
  return origFetch(input, init);
};
```

For richer frontend instrumentation (component renders, route changes),
the same endpoint accepts whatever spans you produce — or point the
OpenTelemetry JS SDK's OTLP exporter at a collector if you prefer.

## Security notes

A world-reachable ingest endpoint is inherently abusable; the defenses are
**throttle + payload bounding + head sampling**, plus whatever `middleware`
you add (auth for a logged-in app, a signed URL, etc.). It never trusts the
client with a secret, caps every input, and clamps timestamps to a sane
window so replayed or clock-skewed batches are rejected. Leave it off unless
you want browser RUM.
