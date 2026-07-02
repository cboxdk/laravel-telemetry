---
title: API reference
description: The whole Telemetry facade on one page
weight: 4
---

# API reference

Everything hangs off the `Cbox\Telemetry\Facades\Telemetry` facade.

## Metrics

```php
// Counter — monotonic, shared across processes
Telemetry::counter('orders.created', 'Orders created')->inc();
Telemetry::counter('orders.created')->inc(5, ['tenant' => 'acme']);

// Push gauge — set, or adjust up/down (active connections, in-flight jobs)
Telemetry::gauge('cache.warmed_keys')->set(1240);
Telemetry::gauge('jobs.in_flight')->increment(labels: ['queue' => 'default']);
Telemetry::gauge('jobs.in_flight')->decrement(labels: ['queue' => 'default']);

// Observable gauge — callback at scrape time, single or multi-series
Telemetry::gauge('queue.depth', fn () => Queue::size(), unit: '{jobs}');
Telemetry::gauge('queue.depth', fn () => [
    [12, ['queue' => 'default']],
    [3,  ['queue' => 'mail']],
]);

// Histogram — distributions, with closure timing
Telemetry::histogram('checkout.duration', unit: 'ms')->record($ms);
Telemetry::histogram('import.duration', buckets: [100, 500, 1000])
    ->time(fn () => $importer->run());
```

## Traces

```php
// Closure form — ends, records exceptions, rethrows
$result = Telemetry::span('billing.recalculate', function ($span) {
    $span->setAttribute('tenant.id', $id);

    return $service->run();
});

// Manual form
$span = Telemetry::span('phase.one', attributes: ['shard' => 3]);
$span->addEvent('checkpoint', ['rows' => 5000]);
$span->recordException($e);
$span->setStatus(SpanStatus::Ok);
$span->updateName('phase.one retried');
$span->end();

// Context
Telemetry::currentSpan();          // ?Span
Telemetry::traceId();              // ?string
Telemetry::traceparent();          // ?string — W3C header value
Telemetry::continueTrace($header); // continue a remote trace
Telemetry::resetContext();         // forget the active trace

// Outbound propagation
Http::withTraceparent()->post($url, $payload);
```

## Events & logs

```php
Telemetry::event('autoscale.decision', ['workers' => 7]);
```

```php
// config/logging.php — ship logs as trace-correlated OTLP log records
'telemetry' => ['driver' => 'telemetry', 'level' => 'info'],
```

## Providers & exporters

```php
Telemetry::provider(new QueueMetricsProvider);          // contract-based
Telemetry::contributes('my-app', fn (Registry $r) => ...); // inline
Telemetry::addExporter(new ClickhouseExporter(...));    // runtime exporter
```

## Export & introspection

```php
Telemetry::collect();       // list<MetricFamily> — what a scrape sees
Telemetry::flush();         // export buffered spans + events now
Telemetry::flushMetrics();  // push metrics to exporters now
Telemetry::enabled();       // bool
Telemetry::handleExceptionsUsing(fn (Throwable $e) => ...);
```

## Testing

```php
$fake = Telemetry::fake();

$fake->assertCounterIncremented('orders.created', ['tenant' => 'acme']);
$fake->assertCounterNotIncremented('orders.cancelled');
$fake->assertGaugeSet('cache.warmed_keys');
$fake->assertHistogramRecorded('checkout.duration');
$fake->assertSpanRecorded('billing.recalculate', fn ($span) => ...);
$fake->assertSpanNotRecorded('other.work');
$fake->assertEventEmitted('autoscale.decision', fn ($event) => ...);
$fake->assertEventNotEmitted('never.happened');

$fake->counterValue('orders.created', ['tenant' => 'acme']);
$fake->gaugeValue('queue.depth');
$fake->histogramCount('checkout.duration');
$fake->recordedSpans('billing.recalculate');
$fake->recordedEvents();
```

## Artisan

```bash
php artisan telemetry:doctor                   # verify store, exporters, config
php artisan telemetry:flush [--wipe]           # push metrics to exporters
php artisan telemetry:dashboards [--export=]   # install the Grafana dashboards
php artisan about                              # shows the Telemetry section
```
