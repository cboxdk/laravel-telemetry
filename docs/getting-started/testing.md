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
