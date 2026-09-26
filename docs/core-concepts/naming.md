---
title: Naming
description: One canonical vocabulary — OpenTelemetry semantic conventions
weight: 6
---

# Naming

There is exactly one canonical vocabulary: **OpenTelemetry semantic
conventions**. Lowercase, dot-namespaced, described, with units:

```text
http.server.request.duration    s      (semconv: seconds, not ms)
http.client.request.duration    s      (semconv: seconds, not ms)
queue.job.duration              s
queue.jobs.processed
system.memory.usage             By
system.cpu.utilization          1      (fraction 0-1)
```

Where a name is a **stable OpenTelemetry metric**, its unit is fixed by
the spec and this package follows it — `http.server.request.duration` and
`http.client.request.duration` are in seconds, with the semconv advisory
buckets. Reusing a semconv name with your own unit is the worst of both
worlds: no stock dashboard matches it, and a collector fed the same name
from another SDK sees one metric arrive with two units.

Names the spec does not define — `queue.job.duration`, `command.duration`,
`schedule.task.duration` — are free choices, but their *unit* is not:
"when instruments are measuring durations, seconds (i.e. `s`) SHOULD be
used". Every duration this package emits is in seconds, so one scale reads
across the stock HTTP metrics and our own, and a dashboard never has to
know which it is looking at.

A dimensionless `1` means a ratio. The Prometheus translation suffixes a
gauge with that unit `_ratio`, so a *count* wants a braced annotation
instead (`{run_queue_item}`, `{entry}`), which carries no suffix.

Prometheus names are derived automatically — dots become underscores and
counters get `_total`:

```text
http_server_request_duration_seconds_bucket{le="0.1"}
queue_jobs_processed_total
```

## Shape is part of the name

A metric's *type* travels with it and decides what arithmetic a backend is
allowed to do. Getting it wrong produces a number that is individually
correct and collectively a lie.

```text
Counter         monotonic; may be rate()'d      system.network.io
UpDownCounter   a sum that can go down          system.memory.usage
Gauge           a level, not a sum              system.cpu.utilization
Histogram       a distribution                  http.server.request.duration
```

Bytes of memory in use are the clearest case. They are a **sum**: add them
across ten hosts and you have the fleet's memory, which is the question you
actually have. Recorded as a gauge, a backend is entitled to average them
instead, and the answer is a tenth of the truth. Conversely, only a counter
may be `rate()`'d, and only a counter's reset is understood as a reboot
rather than a cliff.

Declare it with `Telemetry::observable()` for a reading taken at scrape
time, or `Telemetry::pushed()` for one written into the shared store:

```php
Telemetry::observable('system.memory.usage', $read, MetricType::UpDownCounter, unit: 'By');
Telemetry::pushed('system.network.io', MetricType::Counter, unit: 'By');
```

Prometheus has only four types and no up-down counter, so it receives a
gauge — the same lossy mapping the OTLP-to-Prometheus translation makes.
The distinction survives where it can: in the OTLP payload, as
`isMonotonic: false`.

## Your own metrics

Namespace by domain, most-general first:

```text
orders.created                — not created_orders
checkout.duration             — not time_of_checkout
billing.invoices.overdue      — hierarchy reads left to right
```

Package authors: prefix with the package domain (`queue_autoscale.workers.desired`)
so dashboards group naturally.

## Rules

- Names match `[a-z][a-z0-9._]*` — invalid names throw at registration.
- A name is one instrument type forever; re-registering `orders.created`
  as a histogram after it was a counter throws `InstrumentTypeMismatch`.
- Declare units in the instrument (`unit: 'ms'`, `'By'`, `'1'`) rather than
  in the name; exporters surface them appropriately.
- Label keys follow the same conventions (`http.route`, `tenant.id`).
  Non-conforming characters are sanitized to `_` for Prometheus.
