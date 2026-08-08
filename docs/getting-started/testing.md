---
title: Testing
description: Assert telemetry with Telemetry::fake()
weight: 3
---

# Testing

Swap the manager for an in-memory fake — no Redis, no HTTP, full sampling:

```php
use Cbox\Telemetry\Facades\Telemetry;

it('tracks the order', function () {
    $fake = Telemetry::fake();

    app(PlaceOrder::class)->handle();

    $fake->assertCounterIncremented('orders.created', ['tenant' => 'acme']);
    $fake->assertHistogramRecorded('checkout.duration');
    $fake->assertSpanRecorded('checkout.payment',
        fn ($span) => $span->attributes()['gateway'] === 'stripe');
    $fake->assertEventEmitted('order.placed');
});
```

## Available assertions

| Assertion | Notes |
|---|---|
| `assertCounterIncremented($name, ?$labels)` | labels match exactly |
| `assertCounterNotIncremented($name)` | |
| `assertGaugeSet($name, ?$labels)` | push gauges |
| `assertHistogramRecorded($name, ?$labels)` | |
| `assertSpanRecorded($name, ?$callback)` | callback receives each `Span` |
| `assertSpanNotRecorded($name)` | |
| `assertEventEmitted($name, ?$callback)` | callback receives each `TelemetryEvent` |
| `assertEventNotEmitted($name)` | |

## Reading values

```php
$fake->counterValue('orders.created', ['tenant' => 'acme']); // 2.0
$fake->gaugeValue('queue.depth');                            // observables too
$fake->histogramCount('checkout.duration');
$fake->recordedSpans('import.customers');                    // list<Span>
$fake->recordedEvents();                                     // list<TelemetryEvent>
```

## Testing a telemetry provider

Package authors can test their provider without booting Laravel:

```php
use Cbox\Telemetry\Testing\TelemetryFake;

it('publishes queue metrics', function () {
    $fake = new TelemetryFake;
    $fake->provider(new QueueMetricsProvider);

    $families = collect($fake->collect())->keyBy(fn ($f) => $f->name());

    expect($families['queue.depth']->samples[0]->value)->toBe(12.0);
});
```

## Testing what happens when the backend says no

`Telemetry::fake()` collects batches and answers `ok()` to every export,
so a suite built only on it can never prove that a rejection is noticed.
`Testing\RejectingExporter` is the other half:

```php
use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Support\ExportResult;
use Cbox\Telemetry\Testing\RejectingExporter;

it('fails the scheduled flush when the collector rejects the batch', function () {
    Telemetry::addExporter(new RejectingExporter);

    $this->artisan('telemetry:flush')->assertFailed();
});
```

It defaults to a permanent `HTTP 400`; pass any `ExportResult` to model a
different answer — `ExportResult::retryable('HTTP 503')`,
`ExportResult::partial(3, 'out-of-order sample')` — and `name:` to make it
stand in for a specific exporter. `batches()` returns what it was offered,
because a rejected export still happened.

Flushes return an `ExportReport` you can assert on directly:

```php
$report = Telemetry::flushMetrics();

expect($report->successful())->toBeFalse()
    ->and($report->summary())->toBe('1 of 2 exporters accepted the batch')
    ->and($report->failures()[0]->describe())->toContain('HTTP 400');
```
