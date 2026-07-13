---
title: Quickstart
description: First metrics, spans and events in five minutes
weight: 2
---

# Quickstart

> Want to *see* the output, not just record it? Spin up a local
> Grafana+Tempo+Loki+Prometheus stack in one container and watch your
> first trace land — [Local LGTM stack](cookbook/local-lgtm-stack.md).

## Count things

```php
use Cbox\Telemetry\Facades\Telemetry;

Telemetry::counter('orders.created', 'Orders created')->inc();
Telemetry::counter('mail.sent')->inc(3, ['transport' => 'ses']);
```

Counters are monotonic and live in the shared store — increments from web
requests and queue workers land in the same series.

## Expose current values

Two gauge shapes, chosen by how the value is obtained:

```php
// Observable gauge: evaluated at scrape time, nothing stored.
Telemetry::gauge('queue.depth', fn () => Queue::size());

// Push gauge: set at event time, stored.
Telemetry::gauge('cache.warmed_keys')->set(1240);
```

One observable callback can return many series:

```php
Telemetry::gauge('queue.depth', fn () => [
    [Queue::size('default'), ['queue' => 'default']],
    [Queue::size('mail'), ['queue' => 'mail']],
]);
```

## Measure distributions

```php
Telemetry::histogram('checkout.duration', unit: 'ms')->record($ms);

// Or time a closure directly:
$report = Telemetry::histogram('report.duration', unit: 'ms')
    ->time(fn () => $generator->build());
```

## Trace work

```php
$result = Telemetry::span('import.customers', function ($span) {
    $span->setAttribute('rows', $count);

    return $importer->run();
});
```

Exceptions are recorded on the span and rethrown. Need manual control?
Omit the callback and `->end()` yourself:

```php
$span = Telemetry::span('long.running');
// ...
$span->end();
```

HTTP requests, queue jobs and DB queries are traced automatically (see
configuration). Dispatched jobs continue the dispatcher's trace — the job
span is a child of the dispatch site, across processes.

## Emit events

```php
Telemetry::event('autoscale.decision', [
    'workers.current' => 4,
    'workers.desired' => 7,
]);
```

Events are exported as OTLP log records, correlated to the active trace.

## Where did it go?

- `GET /telemetry/metrics` — Prometheus text format, rendered on demand.
- OTLP backend — spans/events at terminate, metrics via `telemetry:flush`.

## Agent prompt

Have your AI assistant instrument the app's important flows:

```text
Instrument the business-critical flows of this Laravel app with
cboxdk/laravel-telemetry (already installed; use the
Cbox\Telemetry\Facades\Telemetry facade).

First read vendor/cboxdk/laravel-telemetry/docs/getting-started/api-reference.md
and docs/core-concepts/naming.md. Then:

1. Identify the 3-5 most important domain flows (checkout, imports,
   signups, ...) by reading the codebase. Propose the list before coding.
2. For each flow add: a counter for occurrences (OTel-style dot-namespaced
   name, e.g. orders.created), a histogram with unit 'ms' for duration
   where timing matters (prefer ->time(fn () => ...)), and
   Telemetry::event() for decisions worth auditing.
3. Wrap multi-step domain operations in Telemetry::span('domain.op',
   fn ($span) => ...) with bounded attributes.
4. Rules: labels must be bounded values (plan, status, queue) — never ids,
   emails or URLs; do not wrap calls in try/catch; do not add spans for
   HTTP requests, queue jobs or DB queries (auto-instrumented).
5. Write Pest tests using Telemetry::fake() asserting each new metric and
   span (assertCounterIncremented, assertSpanRecorded, ...).
6. Run composer test and show me a summary table of every metric/span/event
   added: name, type, unit, labels.
```
