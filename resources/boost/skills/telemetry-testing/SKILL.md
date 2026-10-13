---
name: telemetry-testing
description: "Test telemetry with Telemetry::fake() from cboxdk/laravel-telemetry. Use when writing or fixing a Pest/PHPUnit test that asserts a counter, gauge, histogram, span or event was recorded, when a test should prove a label value or a cardinality budget, when a test should fail on too many queries, an N+1, duplicate or slow queries, or a slow request (performance budgets), or when testing a telemetry provider or an exporter that rejects a batch."
license: MIT
metadata:
  author: cboxdk
---

# Testing telemetry

## When to use this skill

Use it whenever code under test records telemetry, or when you add an
instrument and need to prove it fires with the right labels.

## Start every test with the fake

```php
use Cbox\Telemetry\Facades\Telemetry;

it('counts the order', function () {
    $fake = Telemetry::fake();

    app(PlaceOrder::class)->handle($order);

    $fake->assertCounterIncremented('orders.created', ['plan' => 'pro']);
    $fake->assertHistogramRecorded('checkout.duration');
    $fake->assertSpanRecorded('checkout.payment',
        fn ($span) => $span->attributes()['payment.gateway'] === 'stripe');
    $fake->assertEventEmitted('order.placed');
});
```

The fake is in memory: no Redis, no HTTP, every trace sampled. Never point a
test at a real store or exporter.

## Assertions

| Signal | Assert | Read |
|---|---|---|
| Counter | `assertCounterIncremented($name, ?$labels)`, `assertCounterNotIncremented($name)` | `counterValue($name, $labels)` |
| Push gauge | `assertGaugeSet($name, ?$labels)`, `assertGaugeNotSet($name)` | `gaugeValue($name, $labels)` (observable gauges too) |
| Histogram | `assertHistogramRecorded($name, ?$labels)`, `assertHistogramNotRecorded($name)` | `histogramCount()`, `histogramSum()` |
| Span | `assertSpanRecorded($name, ?fn (Span $s) => bool)`, `assertSpanNotRecorded($name)` | `recordedSpans($name)` |
| Event | `assertEventEmitted($name, ?fn (TelemetryEvent $e) => bool)`, `assertEventNotEmitted($name)` | `recordedEvents($name)` |

Labels in `assertCounterIncremented()` match exactly. A span exposes
`attributes()`, `events()`, `status()` and `durationMs()`; an event exposes
`$event->attributes`.

## Performance budgets

The fake records every query span, so a feature test can fail on a query
budget. Each request is checked on its own:

```php
$fake = Telemetry::fake();

$this->get('/orders');

$fake->assertQueryCount('<=', 10);   // or assertQueryCount(3) for exactly 3
$fake->assertNoNPlusOne();
$fake->assertNoDuplicateQueries();
$fake->assertNoSlowQueries();        // threshold defaults to slow_span_ms
$fake->assertMaxDuration(200);       // ms, the request's root span
```

`budget()` checks a closure as one unit, across any number of requests, and
fails on N+1 or duplicates unless allowed:

```php
$fake->budget(fn () => $this->get('/orders'), queries: 10, ms: 200);
```

Failures list each offending SQL shape with its count and app line. N+1 is
flagged from 3 repeats; set `TELEMETRY_INSTRUMENT_QUERY_DUPLICATES_THRESHOLD=2`
in `phpunit.xml` for stricter tests, and `TELEMETRY_INSTRUMENT_QUERY_ORIGIN=all`
to get a code line on every query. With Pest, call
`\Cbox\Telemetry\Testing\PestExpectations::register()` in `tests/Pest.php`
for `toStayWithinBudget()`, `toQueryAtMost()`, `toHaveNoNPlusOne()` and
`toHaveNoDuplicateQueries()`.

## When the label value is what you are testing

`assertCounterIncremented($name, $labels)` only proves one series exists. If
the code picks a label value per branch, pin the full set so a dead branch
fails by name:

```php
$fake->recordedMetrics('transit.pairs')
    ->assertLabelValues('stage', ['cache', 'estimated', 'provider'])  // exactly these
    ->assertLabelCardinalityBelow('stage', 10)
    ->assertCardinalityBelow(50);
```

`assertLabelValues()` is exact and requires every series to carry the label;
scope first with `withLabels([...])` or `filter(fn ($sample) => ...)` when the
label is optional. A budget on a metric that recorded nothing fails, so
assert absence explicitly with `assertSeriesCount(0)` or
`assertCounterNotIncremented()`.

Inspect with `labelValues($key)`, `labelCardinality()`, `samples()`,
`seriesCount()` and `total()`, or the shortcuts `$fake->metricLabelValues()`
and `$fake->assertMetricLabelValues()`.

## Testing a provider without booting Laravel

```php
use Cbox\Telemetry\Testing\TelemetryFake;

$fake = new TelemetryFake;
$fake->provider(new QueueMetricsProvider);

$families = collect($fake->collect())->keyBy(fn ($f) => $f->name());
```

## Testing a rejected export

The fake accepts every batch. To prove a rejection is noticed, add
`Cbox\Telemetry\Testing\RejectingExporter`:

```php
Telemetry::addExporter(new RejectingExporter);

$this->artisan('telemetry:flush')->assertFailed();
```

## Pitfalls

- The fake shows what was recorded, not what would be exported. Redaction is
  not applied, so do not assert redaction through it; use a real manager
  with `Testing\CollectingExporter` and flush.
- Do not mock the facade with `Telemetry::shouldReceive()`. Use the fake.

Reference: `vendor/cboxdk/laravel-telemetry/docs/getting-started/testing.md`.
