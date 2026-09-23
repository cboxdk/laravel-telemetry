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
| `assertMetricLabelValues($name, $label, $values)` | the label's observed values, exactly |

On `recordedMetrics()` (below): `assertLabelValues`, `assertSeriesCount`,
`assertCardinalityBelow`, `assertLabelCardinalityBelow`.

## Reading values

```php
$fake->counterValue('orders.created', ['tenant' => 'acme']); // 2.0
$fake->gaugeValue('queue.depth');                            // observables too
$fake->histogramCount('checkout.duration');
$fake->recordedSpans('import.customers');                    // list<Span>
$fake->recordedEvents();                                     // list<TelemetryEvent>
$fake->recordedMetrics('orders.created');                    // RecordedMetrics
$fake->metricLabelValues('orders.created', 'tenant');        // ['acme', 'globex']
```

## Inspecting metrics

`assertCounterIncremented($name, $labels)` answers *does this series
exist?* That is the wrong question whenever a label value is itself the
thing under test: code that attributes a counter across five stages
produces a valid label value on every branch, so an assertion that only
checks one — or checks membership of the vocabulary — passes while four
branches are dead.

`recordedMetrics()` is the metric counterpart to `recordedSpans()`: every
recorded series, with the metric it belongs to, its labels and its value.

```php
$fake->recordedMetrics('transit.pairs')
    ->assertLabelValues('stage', ['cache', 'estimated', 'provider', 'unresolved'])
    ->assertCardinalityBelow(50);
```

`assertLabelValues()` is exact — every expected value must have been
observed and nothing else may appear, so a stage that never fired fails
the test by name. It also requires every series in the set to carry the
label: "exactly these stage values" is a lie by omission if some series
have no `stage` at all. When a label really is optional, scope the set
first with `withLabels()` or `filter()`.

Filters return a new set; assertions return the set, so both chain:

```php
$metrics = $fake->recordedMetrics();               // everything recorded

$metrics->forMetric('orders.created');             // one metric
$metrics->ofType(MetricType::Histogram);           // one instrument type
$metrics->withLabels(['tenant' => 'acme']);        // series carrying these labels
$metrics->filter(fn ($sample) => $sample->value > 100);

$metrics->samples();                               // list<RecordedSample>
$metrics->names();                                 // distinct metric names
$metrics->labelValues('stage');                    // distinct values, sorted
$metrics->labelSets();                             // list<array<string, string>>
$metrics->labelCardinality();                      // ['user' => 4012, 'tenant' => 3]
$metrics->seriesCount();                           // how many series exist
$metrics->total();                                 // sum across every labelset
```

Each `RecordedSample` carries `name`, `type`, `labels`, `value` (the sum,
for a histogram), `count` (histograms only) and the raw `histogram`
sample with its bounds and exemplar. The set is `Countable` and
iterable.

### Cardinality

Cardinality is the reason these labels are worth testing at all, so the
budget assertions are first-class — and a failure names the label that
blew it:

```php
$fake->recordedMetrics('http.requests')
    ->assertCardinalityBelow(200)                       // total series
    ->assertLabelCardinalityBelow('route', 100)         // one label
    ->assertSeriesCount(12);                            // or pin it exactly
```

```
Metric [http.requests] recorded 4013 series, which is not below the budget
of 200. Distinct values per label: user (4012), method (3), status (2).
```

A budget on a metric that recorded **nothing** would pass for the worst
possible reason — the instrumentation never ran — so it fails instead.
Assert an absence deliberately with `assertSeriesCount(0)` or
`assertCounterNotIncremented()`.

## Span resource attributes

The fake measures span resources, exactly as the service provider does for
the real tracer when `instrument.resources` is on (the default), so every
recorded span carries `php.cpu.time_ms` and `php.memory.delta_bytes`:

```php
$fake->span('import.customers', fn () => $importer->run());

$span = $fake->recordedSpans('import.customers')[0];
$span->attributes()['php.cpu.time_ms'];       // float
$span->attributes()['php.memory.delta_bytes']; // int, may be negative
```

To model an app that turned the measurement off, turn it off on the fake's
tracer too:

```php
$fake->tracer()->measureSpanResources(false);
```

Peak-memory and OS-level numbers (`php.memory.peak_bytes`,
`process.memory.rss_peak_bytes`, `process.cpu.utilization`) are per unit of
work, not per span — the request middleware and the queue instrumentation
put them on the root span. See [Traces](../core-concepts/traces.md).

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
