---
title: Telemetry providers
description: Let your package publish telemetry without coupling
weight: 1
---

# Telemetry providers

The provider contract is how packages publish telemetry. The rule:

> Telemetry never knows about your package. Your package publishes
> telemetry **if telemetry exists**.

## The contract

```php
use Cbox\Telemetry\Contracts\TelemetryProvider;
use Cbox\Telemetry\Metrics\Registry;

final class QueueMetricsProvider implements TelemetryProvider
{
    public function name(): string
    {
        return 'cbox.queue-metrics';
    }

    public function register(Registry $registry): void
    {
        $registry->gauge('queue.depth', fn () => QueueMetrics::depth(), unit: '{jobs}');
        $registry->counter('queue_metrics.jobs.recorded');
        $registry->histogram('queue_metrics.runtime', unit: 'ms');
    }
}
```

## Self-registration

In your package's service provider, guarded so the dependency stays
optional:

```php
public function boot(): void
{
    if (class_exists(\Cbox\Telemetry\Facades\Telemetry::class)) {
        \Cbox\Telemetry\Facades\Telemetry::provider(new QueueMetricsProvider);
    }
}
```

Registration is **lazy** — `register()` runs the first time instruments are
actually needed (a scrape, a flush), not at boot. A provider that throws is
reported and skipped; it can never break the host application.

## Inline providers

For app-level or quick integrations:

```php
Telemetry::contributes('my-app', function (Registry $registry) {
    $registry->gauge('users.total', fn () => User::count());
    $registry->gauge('tenants.active', fn () => Tenant::active()->count());
});
```

## Agent prompt

For package authors — paste into your assistant inside the package repo:

```text
Add optional cboxdk/laravel-telemetry support to this Laravel package
without making it a hard dependency:

1. Create src/Telemetry/<PackageName>TelemetryProvider.php implementing
   Cbox\Telemetry\Contracts\TelemetryProvider. name() returns
   '<vendor>.<package>'. In register(Registry $registry) declare:
   - observable gauges (callback form) for state that is cheap to query,
   - counters/histograms for things the package's own event handlers
     record. Names: lowercase dot-namespaced, prefixed with the package
     domain; declare units; bounded labels only.
2. In the package service provider's boot():
   if (class_exists(\Cbox\Telemetry\Facades\Telemetry::class)) {
       \Cbox\Telemetry\Facades\Telemetry::provider(new ...);
   }
3. composer.json: add cboxdk/laravel-telemetry to require-dev and suggest
   (never require).
4. Test with new \Cbox\Telemetry\Testing\TelemetryFake: register the
   provider, call $fake->collect(), assert the expected families and
   values. Also test that the package boots fine WITHOUT telemetry
   installed (the guard).
5. Document the published metrics in the package README: name, type,
   unit, labels.
```

## Guidelines

- Prefix metric names with your package domain (`queue_autoscale.*`).
- Prefer observable gauges for queryable state; push counters/histograms
  from your package's own event handlers for things that happen.
- Keep scrape callbacks cheap — they run on every scrape.
- Test with `TelemetryFake` (see Getting started → Testing).
