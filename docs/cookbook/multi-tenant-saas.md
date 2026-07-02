---
title: "Cookbook: Multi-tenant SaaS"
description: Per-tenant business metrics without blowing up cardinality
weight: 1
---

# Cookbook: Multi-tenant SaaS

Goal: business metrics per tenant, traces per request, and a cardinality
budget that survives tenant growth.

## Business counters with a tenant label

```php
// app/Actions/PlaceOrder.php
Telemetry::counter('orders.created', 'Orders placed')
    ->inc(1, ['tenant' => $tenant->slug, 'plan' => $tenant->plan]);

Telemetry::histogram('orders.value', unit: 'DKK',
        buckets: [50, 100, 250, 500, 1000, 5000])
    ->record($order->total, ['tenant' => $tenant->slug]);
```

**Cardinality rule of thumb:** a `tenant` label is fine into the hundreds
of tenants; in the thousands, label by `plan`/`segment` instead and keep
per-tenant detail in traces and events, which are per-occurrence anyway.

## Tenant context on every span

Tag the root span once, in middleware after tenant resolution:

```php
public function handle(Request $request, Closure $next): Response
{
    Telemetry::currentSpan()?->setAttributes([
        'tenant.id' => $request->tenant()->id,
        'tenant.plan' => $request->tenant()->plan,
    ]);

    return $next($request);
}
```

Now every trace is filterable by tenant in Tempo:
`{ span.tenant.id = "acme" && duration > 1s }`.

## Fleet state as observable gauges

```php
Telemetry::contributes('saas', function (Registry $registry) {
    $registry->gauge('tenants.total', fn () => Tenant::count());

    $registry->gauge('tenants.by_plan', fn () => Tenant::query()
        ->groupBy('plan')
        ->selectRaw('plan, count(*) as n')
        ->pluck('n', 'plan')
        ->map(fn ($n, $plan) => [(float) $n, ['plan' => $plan]])
        ->values()
        ->all());
});
```

One query per scrape, one series per plan.

## Domain events for the audit trail

```php
Telemetry::event('tenant.plan_changed', [
    'tenant.id' => $tenant->id,
    'plan.from' => $old,
    'plan.to' => $new,
]);
```

Queryable in Loki, linked to the trace that caused it.

## Dashboards

```promql
# orders per plan, 5m rate
sum by (plan) (rate(orders_created_total[5m]))

# revenue-weighted p50 order value
histogram_quantile(0.5, sum by (le) (rate(orders_value_bucket[15m])))
```
