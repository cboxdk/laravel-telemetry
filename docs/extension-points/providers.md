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

## Guidelines

- Prefix metric names with your package domain (`queue_autoscale.*`).
- Prefer observable gauges for queryable state; push counters/histograms
  from your package's own event handlers for things that happen.
- Keep scrape callbacks cheap — they run on every scrape.
- Test with `TelemetryFake` (see Getting started → Testing).
