## cboxdk/laravel-telemetry

This application uses cboxdk/laravel-telemetry for metrics, traces, events and logs. Use the `Cbox\Telemetry\Facades\Telemetry` facade.

### Choosing the right instrument

- Something happened → counter: `Telemetry::counter('orders.created')->inc(1, ['tenant' => $slug])`. Counters are monotonic; negative increments are ignored.
- Current value, queryable on demand → observable gauge: `Telemetry::gauge('queue.depth', fn () => Queue::size())`. The callback runs at scrape time — keep it cheap, never do heavy queries in it.
- Current value that goes up AND down at event time → push gauge: `Telemetry::gauge('jobs.in_flight')->increment()` / `->decrement()`.
- Distribution (durations, sizes) → histogram: `Telemetry::histogram('checkout.duration', unit: 'ms')->record($ms)` or `->time(fn () => ...)` to measure a closure.
- A decision or state transition you will query later → `Telemetry::event('autoscale.decision', ['workers' => 7])`.
- Traced work → `Telemetry::span('import.customers', fn ($span) => ...)`. The closure form ends the span, records exceptions and rethrows — prefer it over manual `->end()`.

### Rules

- Metric names: lowercase, dot-namespaced, OTel-style (`orders.created`, `billing.invoices.overdue`). Names match `[a-z][a-z0-9._]*`. A name keeps one instrument type forever.
- Declare units in the instrument (`unit: 'ms'`, `'By'`, `'1'`), never in the name.
- Label values must be bounded: route patterns, status codes, queue names, plans. NEVER user ids, emails, URLs or UUIDs as label values — put those on span attributes or events instead.
- Do not wrap telemetry calls in try/catch and do not check `Telemetry::enabled()` — recording never throws and no-ops when disabled.
- HTTP requests, queue jobs and DB queries are auto-instrumented; do not add manual spans for those.
- For outbound HTTP to services you own, use `Http::withTraceparent()->post(...)` so the trace continues across services. Do not add the header for third-party APIs.
- Queue jobs automatically continue the dispatcher's trace — never propagate trace ids through job properties manually.

### Testing

- Always use `$fake = Telemetry::fake();` in tests — never hit Redis or real exporters.
- Assert with `$fake->assertCounterIncremented('orders.created', ['tenant' => 'acme'])`, `assertSpanRecorded('name', fn ($span) => ...)`, `assertHistogramRecorded()`, `assertEventEmitted()`, plus the negative variants (`assertCounterNotIncremented`, `assertSpanNotRecorded`, `assertEventNotEmitted`).
- Read values with `$fake->counterValue()`, `gaugeValue()`, `histogramCount()`, `recordedSpans()`, `recordedEvents()`.

### Publishing telemetry from a package

Register a provider guarded by `class_exists` so the dependency stays optional:

```php
if (class_exists(\Cbox\Telemetry\Facades\Telemetry::class)) {
    \Cbox\Telemetry\Facades\Telemetry::provider(new MyPackageTelemetryProvider);
}
```

Or inline: `Telemetry::contributes('my-domain', fn (\Cbox\Telemetry\Metrics\Registry $r) => $r->gauge('my_domain.things', fn () => Thing::count()));`

### Operations

- Verify any setup change with `php artisan telemetry:doctor` (store round trip, exporter reachability, config warnings).
- Prometheus scrape endpoint: `GET /telemetry/metrics` (config `telemetry.prometheus`).
- OTLP metrics need the scheduler: `Schedule::command('telemetry:flush')->everyMinute()->onOneServer();`
- Ship logs as trace-correlated OTLP records by adding the `telemetry` log channel to the stack in `config/logging.php`: `['driver' => 'telemetry', 'level' => 'info']`.
- Full docs live in `vendor/cboxdk/laravel-telemetry/docs/` (start at `docs/getting-started/api-reference.md`).
