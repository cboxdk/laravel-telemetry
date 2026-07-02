---
title: Quickstart
description: First metrics, spans and events in five minutes
weight: 2
---

# Quickstart

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
