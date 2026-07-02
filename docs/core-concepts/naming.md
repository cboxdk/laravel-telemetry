---
title: Naming
description: One canonical vocabulary — OpenTelemetry semantic conventions
weight: 5
---

# Naming

There is exactly one canonical vocabulary: **OpenTelemetry semantic
conventions**. Lowercase, dot-namespaced, described, with units:

```text
http.server.request.duration    ms
db.client.query.duration        ms
queue.job.duration              ms
queue.jobs.processed
system.memory.usage             By
system.cpu.utilization          1      (fraction 0-1)
```

Prometheus names are derived automatically — dots become underscores and
counters get `_total`:

```text
http_server_request_duration_bucket{le="100"}
queue_jobs_processed_total
```

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
