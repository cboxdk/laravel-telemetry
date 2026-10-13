---
name: telemetry-debugging-performance
description: "Find out why a Laravel request or job is slow using the trace data cboxdk/laravel-telemetry records. Use when a page or endpoint is slow, when you suspect an N+1 or duplicate queries, when you need to compare a request before and after a fix, or when you need every trace and a CPU profile for one user, tenant, route or job for a while."
license: MIT
metadata:
  author: cboxdk
---

# Debugging a slow request with trace data

## When to use this skill

Use it when someone reports "this is slow" or "too many queries" and the app
runs laravel-telemetry. The trace already holds the answer; read it before
adding logging or timers. If `cboxdk/laravel-telemetry-ui` is installed, its
MCP tools (`trace_summary`, `compare_traces`) do the reading for you; see the
`telemetry-ui-mcp` skill.

## 1. Get the trace id

- Every traced response carries an `X-Trace-Id` header
  (`traces.response_header`).
- In code or an error page: `Telemetry::traceId()`.
- Locally, keep every trace: `TELEMETRY_TRACES_SAMPLE_RATE=1.0`.
- With `traces.server_timing` on (opt-in), the browser's DevTools show db,
  cache, redis, telemetry and app time from the `Server-Timing` header.

## 2. Read the root span first

The request's root span carries tallies that are kept even when detail
spans are trimmed:

| Attribute | Tells you |
|---|---|
| `db.query.count`, `db.query.time_ms` | how many queries, how long in total |
| `db.query.n_plus_one.count` | distinct N+1 query shapes |
| `db.query.duplicate.count` | distinct queries repeated with identical values |
| `db.query.slow.count` | queries at or over `traces.details.slow_span_ms` |
| `model.hydrations` | models hydrated (a proxy for loading too much) |
| `laravel.routing_ms`, `laravel.middleware_ms`, `laravel.handler_ms`, `laravel.send_ms`, `laravel.terminate_ms` | which phase took the time |
| `php.cpu.time_ms`, `php.memory.peak_bytes` | CPU and memory of this request |
| `telemetry.overhead_ms` | what the package's own hooks cost |

A big `laravel.handler_ms` with low `db.query.time_ms` is your code or a
view; a big `laravel.middleware_ms` is the middleware stack.

## 3. Tell N+1 from duplicates

With `instrument.query_duplicates` on (the default), a query repeated
`instrument.query_duplicates_threshold` times (default 3) is flagged:

- **N+1**: same SQL shape, different values. Event
  `db.query.n_plus_one_detected`. Fix: eager load (`with()`), or `whereIn`.
- **Duplicate**: same SQL, same values. Event `db.query.duplicate_detected`.
  Fix: memoize, cache, or pass the result down.

Both events carry the SQL, `laravel.db.query.shape` and the app line that
ran it as `code.file.path` / `code.line.number`. Go to that file and line
first: it is the loop or the Blade view, not Eloquent's internals. Every
query span carries `laravel.db.query.shape`; spans in a flagged group also
carry `laravel.db.query.repeat` (`n_plus_one` or `duplicate`).

In Tempo or Grafana:

```traceql
{ span.db.query.n_plus_one.count > 0 && kind = server }
{ span.laravel.db.query.repeat = "n_plus_one" }
```

Set `instrument.query_origin` to `all` locally if you want the app line on
every query span, not only the flagged ones.

## 4. Compare before and after

Label the request instead of copying trace ids:

```bash
curl -H 'X-Telemetry-Label: before' https://app.test/orders
# apply the fix
curl -H 'X-Telemetry-Label: after' https://app.test/orders
```

The value lands on every span as `laravel.trace.label`. Compare the two
root spans' tallies, or query `{ span.laravel.trace.label = "after" }`.

## 5. Record more of one slice, for a while

Do not raise the global sample rate and do not add ad-hoc logging. Place a
directive; it expires on its own:

```bash
php artisan telemetry:watch --route="orders.*" --minutes=30 --profile --reason="slow orders page"
php artisan telemetry:watch --user=42 --minutes=60
php artisan telemetry:watch --context=tenant.id=7
php artisan telemetry:watch --list     # --remove=<id>, --clear
```

Or in code: `Telemetry::directives()->add(Directive::route('orders.*')->profile()->for(minutes: 30))`
(`Cbox\Telemetry\Directives\Directive`). Kept traces carry
`telemetry.directive`. With a profiler extension installed, slow requests
also emit a `profile.captured` event with the top functions.

## 6. Verify the fix with a test

Write a test with `Telemetry::fake()` that asserts the N+1 is gone, e.g.
`$fake->assertEventNotEmitted('db.query.n_plus_one_detected')` after
exercising the endpoint (see the `telemetry-testing` skill). The query
listeners are only registered when telemetry is enabled, so the test
environment must not set `TELEMETRY_ENABLED=false`.

Reference: `vendor/cboxdk/laravel-telemetry/docs/core-concepts/traces.md`
(sections "Query diagnostics", "Request phases" and "Directives").
