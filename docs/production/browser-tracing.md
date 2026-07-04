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

## Turnkey: one directive

Add `@telemetryBrowser` to your layout `<head>` and you're done — it emits
the traceparent meta **and** a bundled, zero-dependency RUM script:

```blade
<head>
    @telemetryBrowser
    ...
</head>
```

That script (served from your app, cached) roots the browser trace on the
server trace, records a `document.load` span, instruments `fetch`
(propagating `traceparent` to **same-origin** calls so backend spans join
the trace — cross-origin is skipped to avoid CORS preflight), and captures
uncaught JS errors as error spans. Tune it in config:

```php
'ingest' => ['spans' => [
    'browser' => [
        'fetch' => true,    // instrument fetch + propagate traceparent
        'errors' => true,   // capture uncaught errors
        'sample' => 1.0,    // client-side head sampling (0–1)
    ],
]],
```

Publish it to your own build/CDN instead of serving it live:
`php artisan vendor:publish --tag=telemetry-assets`.

Prefer to roll your own? Use `@telemetryTraceparent` (just the meta) and
POST to the endpoint yourself — the contract and a minimal snippet are
below.

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

## Or roll your own

No SDK required — `@telemetryTraceparent` plus a few lines get you page-load + fetch spans:

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

## Source maps (symbolication)

Minified browser stacks (`app-abc.js:1:2481`) are useless on their own, and
the file names shift every deploy. Upload your build's source maps, keyed by
**release**, and stacks resolve back to original source/line/column/name —
so browser error grouping and detail get as good as the backend's.

Enable the upload endpoint (off, and secure, by default — it stores what it
receives, so a token is **required**, never accidentally open):

```dotenv
TELEMETRY_SOURCEMAPS=true
TELEMETRY_SOURCEMAPS_TOKEN=a-long-random-secret   # CI holds this, not the browser
```

Upload from your build pipeline. With
[`@cboxdk/telemetry-browser`](https://github.com/cboxdk/laravel-telemetry)
it's one CLI call (or the bundled Vite plugin):

```bash
npx telemetry-sourcemaps \
  --dir dist/assets --release "$GIT_SHA" \
  --endpoint https://app.example.com/telemetry/sourcemaps \
  --token "$TELEMETRY_SOURCEMAPS_TOKEN"
```

Each `.map` is POSTed as `{ release, name, map }` with a `Bearer` token,
validated as a v3 map, size-capped (`sourcemaps.max_bytes`), and stored on
`sourcemaps.disk` under `sourcemaps.prefix/<release>/<name>`. Set the same
`release` on the SDK (`init({ release })`) so browser spans and their maps
line up. The `Symbolicator` service (a self-contained VLQ decoder — no ext,
no library) resolves stacks against them at read time:

```php
app(\Cbox\Telemetry\Support\Symbolicator::class)
    ->symbolicateStack($release, $exceptionStacktrace);
// -> [['function' => 'checkout', 'file' => 'src/checkout.ts',
//      'line' => 42, 'column' => 7, 'original' => true], ...]
```

Symbolication is a **read-time** concern: the raw minified stack is stored
as-is on the exception span, and an issues UI (or your own tooling) resolves
it on demand via `Symbolicator`. Maps never have to be public.

Uploads never fail your build loudly and never throw into a request — a
missing or malformed map just leaves the frame minified (`original: false`).

## Security notes

A world-reachable ingest endpoint is inherently abusable; the defenses are
**throttle + payload bounding + head sampling**, plus whatever `middleware`
you add (auth for a logged-in app, a signed URL, etc.). It never trusts the
client with a secret, caps every input, and clamps timestamps to a sane
window so replayed or clock-skewed batches are rejected. Leave it off unless
you want browser RUM.
