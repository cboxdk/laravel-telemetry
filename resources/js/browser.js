/*!
 * cboxdk/laravel-telemetry — browser RUM (zero-dependency, no build step).
 *
 * Roots the browser trace on the server trace (<meta name="traceparent">),
 * records a page-load span, instruments fetch (propagating W3C traceparent
 * to same-origin calls so backend spans join the trace), and optionally
 * captures uncaught errors. Spans are batched and shipped with sendBeacon.
 *
 * Configured via data-* on its own <script> tag (see the @telemetryBrowser
 * Blade directive): data-endpoint, data-fetch, data-errors, data-sample.
 */
(function () {
  'use strict';

  var script = document.currentScript;
  if (!script) return;

  var endpoint = script.getAttribute('data-endpoint');
  if (!endpoint) return;

  var doFetch = script.getAttribute('data-fetch') !== '0';
  var doErrors = script.getAttribute('data-errors') !== '0';
  var sample = parseFloat(script.getAttribute('data-sample') || '1');
  // The shared analytics session.id, propagated from the server (only when
  // analytics is enabled). Stamped on every span so browser and server share
  // one visit key.
  var session = script.getAttribute('data-session');

  // Head sampling — decide once per page; keep whole traces.
  if (sample < 1 && Math.random() > sample) return;

  function hex(bytes) {
    var a = new Uint8Array(bytes);
    (window.crypto || {}).getRandomValues
      ? window.crypto.getRandomValues(a)
      : a.forEach(function (_, i) { a[i] = Math.floor(Math.random() * 256); });
    return Array.prototype.map.call(a, function (b) {
      return ('0' + b.toString(16)).slice(-2);
    }).join('');
  }

  // Root on the server trace when present, else start a fresh browser trace.
  var meta = document.querySelector('meta[name=traceparent]');
  var parts = meta ? (meta.getAttribute('content') || '').split('-') : [];
  var traceId = parts.length === 4 ? parts[1] : hex(16);
  var rootSpanId = parts.length === 4 ? parts[2] : hex(8);

  var origin = location.origin;
  var buffer = [];
  var MAX = 128;

  function push(span) {
    if (buffer.length < MAX) buffer.push(span);
  }

  function flush() {
    if (!buffer.length || !navigator.sendBeacon) { buffer = []; return; }
    var batch = buffer.splice(0, buffer.length);
    try {
      navigator.sendBeacon(endpoint, JSON.stringify({ spans: batch }));
    } catch (e) { /* best effort */ }
  }

  function span(name, kind, start, end, attributes, parentId, status) {
    var attrs = attributes || {};
    if (session) attrs['session.id'] = session;
    push({
      traceId: traceId,
      spanId: hex(8),
      parentSpanId: parentId || rootSpanId,
      name: name,
      kind: kind || 'internal',
      start: Math.round(start),
      end: Math.round(end),
      attributes: attrs,
      status: status || 'ok'
    });
  }

  // --- Page load ---
  addEventListener('load', function () {
    try {
      var nav = performance.getEntriesByType('navigation')[0];
      var t0 = performance.timeOrigin;
      if (nav) {
        span('document.load', 'client', t0 + nav.startTime, t0 + nav.loadEventEnd, {
          'http.url': location.href,
          'browser.ttfb_ms': Math.round(nav.responseStart - nav.startTime),
          'browser.dom_interactive_ms': Math.round(nav.domInteractive - nav.startTime)
        });
      }
    } catch (e) { /* ignore */ }
    setTimeout(flush, 0);
  });

  // --- fetch instrumentation ---
  if (doFetch && window.fetch) {
    var orig = window.fetch;
    window.fetch = function (input, init) {
      init = init || {};
      var url = typeof input === 'string' ? input : (input && input.url) || '';
      var sameOrigin = url.indexOf('/') === 0 || url.indexOf(origin) === 0;
      var spanId = hex(8);

      // Only propagate to same-origin calls — a traceparent header on a
      // cross-origin request trips CORS preflight.
      if (sameOrigin) {
        var headers = new Headers(init.headers || (typeof input !== 'string' ? input.headers : undefined) || {});
        headers.set('traceparent', '00-' + traceId + '-' + spanId + '-01');
        init.headers = headers;
      }

      var start = performance.timeOrigin + performance.now();
      return orig.call(this, input, init).then(function (res) {
        span('fetch ' + (init.method || 'GET'), 'client', start, performance.timeOrigin + performance.now(),
          { 'http.url': url, 'http.response.status_code': res.status }, spanId,
          res.status >= 500 ? 'error' : 'ok');
        return res;
      }, function (err) {
        span('fetch ' + (init.method || 'GET'), 'client', start, performance.timeOrigin + performance.now(),
          { 'http.url': url, 'error': true }, spanId, 'error');
        throw err;
      });
    };
  }

  // --- uncaught errors ---
  if (doErrors) {
    addEventListener('error', function (e) {
      var now = performance.timeOrigin + performance.now();
      span('exception', 'internal', now, now, {
        'exception.type': (e.error && e.error.name) || 'Error',
        'exception.message': String(e.message || '').slice(0, 1024),
        'exception.file': String(e.filename || '').slice(0, 512),
        'exception.line': e.lineno || 0,
        browser: true
      }, null, 'error');
    });
    addEventListener('unhandledrejection', function (e) {
      var now = performance.timeOrigin + performance.now();
      span('exception', 'internal', now, now, {
        'exception.type': 'UnhandledRejection',
        'exception.message': String((e.reason && e.reason.message) || e.reason || '').slice(0, 1024),
        browser: true
      }, null, 'error');
    });
  }

  // Ship on the way out — the reliable moment for RUM.
  addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') flush();
  });
  addEventListener('pagehide', flush);
})();
