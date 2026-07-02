---
title: Events
description: Structured events as trace-correlated OTLP log records
weight: 4
---

# Events

Events capture *things that happened* — decisions, state transitions,
milestones — with structured attributes:

```php
Telemetry::event('autoscale.decision', [
    'workers.current' => 4,
    'workers.desired' => 7,
    'reason' => 'queue_depth',
]);
```

Emitted inside a span, the event carries the trace and span id — your
backend can show it on the trace timeline:

```php
Telemetry::span('autoscale.evaluate', function () {
    // ...
    Telemetry::event('autoscale.decision', ['workers.desired' => 7]);
});
```

Events buffer with spans and export at terminate as OTLP log records
(`/v1/logs`, severity INFO, body = event name).

## Events vs. span events vs. metrics

- **`Telemetry::event()`** — standalone record, queryable in your log/trace
  backend. Use for domain decisions you'll want to look up later.
- **`$span->addEvent()`** — a timestamped annotation *inside* one span.
  Use for checkpoints during traced work.
- **Counter** — when you only care how often, not each occurrence.
