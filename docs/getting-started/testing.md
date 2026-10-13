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

## What the fake does not do

Redaction. The engine is the last thing that touches a batch before an
exporter sees it, and the fake collects spans in place of exporting
them — so the assertions below show what was **recorded**, not what
would be **sent**. A test asserting that a secret was redacted passes
here whether or not the engine would have caught it.

To assert redaction, use a real manager with a `CollectingExporter` and
flush it.

## Available assertions

| Assertion | Notes |
|---|---|
| `assertCounterIncremented($name, ?$labels)` | labels match exactly |
| `assertCounterNotIncremented($name)` | |
| `assertGaugeSet($name, ?$labels)` | push gauges |
| `assertGaugeNotSet($name)` | |
| `assertHistogramRecorded($name, ?$labels)` | |
| `assertHistogramNotRecorded($name)` | |
| `assertSpanRecorded($name, ?$callback)` | callback receives each `Span` |
| `assertSpanNotRecorded($name)` | |
| `assertEventEmitted($name, ?$callback)` | callback receives each `TelemetryEvent` |
| `assertEventNotEmitted($name)` | |
| `assertMetricLabelValues($name, $label, $values)` | the label's observed values, exactly |
| `assertQueryCount($op, $n)`, `assertNoNPlusOne()`, `assertNoDuplicateQueries()`, `assertNoSlowQueries(?$ms)`, `assertMaxDuration($ms)` | per recorded trace — see [Performance assertions](#performance-assertions) |

Reading values: `counterValue`, `gaugeValue`, `histogramCount`, `histogramSum`.

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

## Performance assertions

The query instrumentation already counts every query, groups them by SQL
shape, tells an N+1 from a duplicate and records the line of code that
ran it. The fake reads that back, so a test can hold a code path to a
budget:

```php
use Cbox\Telemetry\Facades\Telemetry;

it('lists orders without an N+1', function () {
    Telemetry::fake();

    $this->get('/orders')->assertOk();

    Telemetry::assertQueryCount('<=', 10);
    Telemetry::assertNoNPlusOne();
    Telemetry::assertNoDuplicateQueries();
    Telemetry::assertNoSlowQueries();     // traces.details.slow_span_ms, or pass a threshold
    Telemetry::assertMaxDuration(200);    // ms, the request's root span
});
```

Each assertion checks **every trace recorded since `fake()`, one at a
time**. Two requests in one test are two budgets, and the failure names
the one that blew it. A failure lists each SQL shape with how often it
ran and where:

```
[GET /orders] has 1 N+1 query pattern — one statement run again and again
with different values, typically once per row of something loaded earlier.
Eager-load the relation (with()/load()) or fetch the set with one whereIn().
   20× select * from "order_items" where "order_items"."order_id" = ?  (3.1 ms)
        at app/Http/Controllers/OrderController.php:42 (App\Http\Controllers\OrderController::index)
```

`assertQueryCount(3)` means exactly three; with an operator, use one of
`=`, `!=`, `<`, `<=`, `>` or `>=`.

### A budget around a closure

`budget()` runs a closure and checks everything it did as one profile,
however many requests or traces that covers. It returns what the closure
returns:

```php
$orders = Telemetry::budget(
    fn () => Order::with('items')->latest()->take(50)->get(),
    queries: 2,
    ms: 100,                    // wall time of the closure
);
```

A budget fails on any N+1 or duplicate query even when the count fits. If
the loop is intended, pass `allowNPlusOne: true` or `allowDuplicates: true`.
Code that runs outside a request is wrapped in a `telemetry.budget` span,
because the query instrumentation only records queries inside a trace.

### Scoping to one request

`profiles()` returns one `PerformanceProfile` per trace, in the order the
traces started. Each profile has the same assertions, and they chain:

```php
$this->get('/orders');
$this->get('/orders/1');

[$index, $show] = Telemetry::profiles();

$index->assertQueryCount('<=', 10)->assertNoNPlusOne();
$show->assertQueryCount('<=', 3);

$index->queryCount();   // int
$index->queryGroups();  // list<QueryGroup>: sql, count, durationMs, origin, nPlusOne, duplicate
$index->nPlusOnes();    // the flagged groups
$index->durationMs();
```

### Pest expectations

Pest is not a dependency of this package, so its expectations are opt-in.
Register them once in `tests/Pest.php`:

```php
\Cbox\Telemetry\Testing\PestExpectations::register();
```

```php
Telemetry::fake();

expect(fn () => $this->get('/orders'))->toStayWithinBudget(queries: 10, ms: 200);
expect(fn () => $this->get('/orders'))->toQueryAtMost(10);
expect(fn () => $this->get('/orders'))->toHaveNoNPlusOne();
expect(fn () => $this->get('/orders'))->toHaveNoDuplicateQueries();
```

Each expectation runs the closure once, through `budget()`.

### What these read, and when they refuse

- **Query count**: the root span's `db.query.count` tally, or the number
  of `db.query` spans, whichever is higher. The tally still counts queries
  that fall under `instrument.queries_min_duration`.
- **N+1 and duplicates**: whatever the detector flagged. A shape counts
  once it repeats `instrument.query_duplicates_threshold` times (default
  3) in one trace. To catch a two-row loop in tests, set
  `TELEMETRY_INSTRUMENT_QUERY_DUPLICATES_THRESHOLD=2` in `phpunit.xml`.
- **Origins**: by default only flagged queries (slow or repeated) carry
  `code.*`. To get a line for every shape in a query-count failure, set
  `TELEMETRY_INSTRUMENT_QUERY_ORIGIN=all` in `phpunit.xml`.

An assertion that cannot see anything fails rather than passing. That
happens in two cases: no trace was recorded (the request was made before
`fake()`, or request instrumentation is off), or the switch it reads is
off (`telemetry.enabled`, `instrument.queries`, or, for N+1 and
duplicates, `instrument.query_duplicates`). The failure message names the
switch to turn on.

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
