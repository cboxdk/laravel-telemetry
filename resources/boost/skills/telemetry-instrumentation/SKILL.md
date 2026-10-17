---
name: telemetry-instrumentation
description: "Add metrics, spans and events to Laravel code with cboxdk/laravel-telemetry. Use when instrumenting a business flow (orders, imports, billing), adding a counter, gauge or histogram, wrapping work in a span, naming a metric, choosing labels, or publishing telemetry from a package. Covers instrument choice, OTel naming and units, bounded labels, context dimensions and what is already auto-instrumented."
license: MIT
metadata:
  author: cboxdk
---

# Instrumenting code with laravel-telemetry

## When to use this skill

Use it when you are about to write `Telemetry::` calls: a new metric, a span
around some work, a structured event, or a provider in a package. Everything
goes through the `Cbox\Telemetry\Facades\Telemetry` facade.

## 1. Check it is not already instrumented

HTTP requests, queue jobs (dispatch counts, wait time), DB queries, cache,
scheduled tasks, mail, notifications, outgoing `Http` client calls, reported
exceptions, Livewire, Horizon, Reverb, Pennant and `laravel/ai` agents
(`gen_ai.*` spans and token metrics) are instrumented automatically. Do not add spans or counters for those. Instrument the
business meaning instead: "order placed", "invoice overdue", "import rows".

For AI spend, don't multiply token counts yourself: put the app's prices
(per 1M tokens) in `instrument.ai_pricing.models`, keyed by model or
`provider/model` with `*` for snapshots. Priced calls then carry
`gen_ai.usage.cost` and feed the `gen_ai.client.cost` counter. For spend
per user or tenant, turn on `instrument.ai_usage_events` rather than
logging usage by hand; bounded splits like plan go in
`instrument.ai_metric_labels`.

A queued job is a child span of the request that dispatched it. For a job
delayed by hours or fanned out by the thousand, implement
`Cbox\Telemetry\Contracts\StartsLinkedTrace` (or list the class in
`queue.linked_traces`): it starts its own trace, linked to the dispatch.

## 2. Pick the instrument

| You want | Use |
|---|---|
| Something happened (count it) | `Telemetry::counter('orders.created')->inc(1, ['plan' => $plan])` |
| A value read on demand | `Telemetry::gauge('queue.depth', fn () => Queue::size())` (callback runs at scrape time, keep it cheap) |
| A value that moves up and down as things happen | `Telemetry::gauge('imports.in_flight')->increment()` / `->decrement()` / `->set($n)` |
| A distribution (durations, sizes) | `Telemetry::histogram('checkout.duration', unit: 's')->record($seconds)` or `->time(fn () => ...)` |
| A decision you will query later | `Telemetry::event('autoscale.decision', ['workers' => 7])` |
| Timed, traced work | `Telemetry::span('import.customers', fn ($span) => ...)` |

Push gauges (`gauge('x')`) and observable gauges (`gauge('x', fn () => ...)`)
are different shapes. Do not mix them for one name.

## 3. Name it

- Lowercase, dot-namespaced OTel style: `orders.created`,
  `billing.invoices.overdue`. Names match `[a-z][a-z0-9._]*`.
- One name keeps one instrument type forever.
- Units go in the instrument (`unit: 's'`, `'By'`, `'1'`, `'{jobs}'`), never in
  the name. Durations are in seconds.
- Prometheus names (`orders_created_total`) are derived. Never write them.

## 4. Keep labels bounded

Metric label values must come from a small, fixed set: plan, status, queue,
route pattern, region. Never user ids, emails, URLs, UUIDs, cache keys or
free text: every distinct value is a permanent series in the store.

High-cardinality facts go on spans and events instead:

```php
$result = Telemetry::span('billing.recalculate', function ($span) use ($invoice) {
    $span->setAttribute('invoice.id', $invoice->id); // fine on a span
    return $this->recalculator->run($invoice);
}, attributes: ['billing.mode' => 'full']);
```

The closure form ends the span, records an exception and rethrows it. Prefer
it to the manual `$span = Telemetry::span('x'); ... $span->end();`.

For facts that should follow the whole request and the jobs it dispatches,
set them once (middleware, after auth/tenant resolution):

```php
Telemetry::context(['tenant.id' => $tenant?->id, 'plan' => $plan]); // null removes a key
```

Context goes to spans, events and logs, not to metric labels. For a bounded
label on every request metric use `Telemetry::labelRequestsUsing(fn ($request) => ['plan' => ...])`.

## 5. Do not guard it

Recording never throws into the app and is a no-op when telemetry is
disabled. Do not wrap calls in `try/catch` and do not check
`Telemetry::enabled()` first.

## 6. Publishing telemetry from a package

Keep the dependency optional and register a provider:

```php
if (class_exists(\Cbox\Telemetry\Facades\Telemetry::class)) {
    \Cbox\Telemetry\Facades\Telemetry::provider(new MyPackageTelemetryProvider);
}
```

A provider implements `Cbox\Telemetry\Contracts\TelemetryProvider`
(`name()` and `register(Registry $registry)`). For a one-off, use
`Telemetry::contributes('my-domain', fn (Registry $r) => $r->gauge('my_domain.things', fn () => Thing::count()))`.

## 7. Prove it

Every new instrument gets a test with `Telemetry::fake()` (see the
`telemetry-testing` skill). Check the wiring with `php artisan telemetry:doctor`.

Reference: `vendor/cboxdk/laravel-telemetry/docs/getting-started/api-reference.md`
and `docs/core-concepts/naming.md`.
