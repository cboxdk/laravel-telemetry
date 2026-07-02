---
title: Logs
description: Ship Laravel logs as trace-correlated OTLP log records
weight: 5
---

# Logs

The `telemetry` log channel turns ordinary Laravel logging into OTLP log
records — with Monolog severity mapped onto the OTLP range and, crucially,
**correlated to the active trace**, so a log line appears on the exact span
that produced it.

## Setup

Add the channel to your stack in `config/logging.php`:

```php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['single', 'telemetry'],
    ],

    'telemetry' => [
        'driver' => 'telemetry',
        'level' => env('TELEMETRY_LOG_LEVEL', 'info'),
    ],
],
```

Nothing else changes — `Log::info()`, `report()`, `logger()` all flow
through the stack as usual:

```php
Telemetry::span('import.customers', function () {
    Log::info('Import started', ['file' => $path]);
    // → OTLP log record with this span's traceId + spanId
});
```

## What gets exported

| Log record part | OTLP field |
|---|---|
| message | `body` |
| Monolog level | `severityNumber` (DEBUG 5, INFO 9, NOTICE 10, WARNING 13, ERROR 17, CRITICAL 21, ALERT 22, EMERGENCY 23) + `severityText` |
| channel | `log.channel` attribute |
| scalar context | `log.context.*` attributes |
| `exception` context | `exception.type` + `exception.message` attributes |
| other non-scalars | JSON-encoded `log.context.*` |
| active trace | `traceId` + `spanId` |

Records buffer with spans/events and export at terminate to every exporter
supporting the events signal (`/v1/logs` on OTLP).

## Feedback-loop protection

Log records emitted *while a flush is exporting* are dropped — a failing
exporter that reports through the logging stack can never feed itself.

## Logs vs. events

- **`telemetry` channel** — your existing operational logging, exported.
  Free-text messages, severities, exceptions.
- **`Telemetry::event()`** — deliberate structured records (decisions,
  state transitions) with a stable name you'll query by.

Both end up as OTLP log records; use the channel for what you already log
and events for what you want to *query*.
