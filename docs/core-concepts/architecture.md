---
title: Architecture
description: How signals flow from instruments to backends
weight: 1
---

# Architecture

```text
Your app / Cbox packages
  │
  │  Telemetry facade / TelemetryProvider contract
  ▼
TelemetryManager
  ├── Registry ──► MetricStore (redis | apcu | array)   ← push instruments
  │        └────► ObservableGauge callbacks             ← pull instruments
  ├── Tracer  ──► in-memory span buffer (capped)
  └── events  ──► in-memory event buffer
  │
  ▼
Exporters (filtered by SignalSet)
  ├── Prometheus  — scrape route renders store + observables on demand
  ├── OTLP        — direct HTTP JSON: /v1/traces /v1/metrics /v1/logs
  └── Null / custom
```

## The two metric mechanisms

**Push** (`counter`, `gauge()->set()`, `histogram`): written at event time
to the shared store with atomic operations. This is what makes metrics
correct under shared-nothing PHP — every FPM worker, queue worker and node
increments the same Redis series.

**Pull** (`gauge($name, $callback)`): evaluated at scrape/flush time,
nothing stored. Use for values the source of truth can answer cheaply
(queue depth, user count, workers running).

These are deliberately distinct API shapes. A counter you `inc()` and a
gauge callback are different machines — the API never blurs them.

## Span lifecycle

1. `span()` starts a span as a child of the current span (or of a remote
   parent continued from a `traceparent` header / queue payload).
2. The sample decision is made once at the trace root and inherited.
3. Finished sampled spans buffer in memory (default cap 5000 — the buffer
   force-flushes when full).
4. The buffer flushes once per request (terminable middleware), after each
   queue job, and after commands.

## Export timing

| Signal | Prometheus | OTLP |
|---|---|---|
| Metrics | rendered at scrape | pushed by scheduled `telemetry:flush` |
| Traces | — | at terminate (after the response is sent) |
| Events | — | at terminate, as log records |

OTLP metrics are exported with **cumulative temporality** read from the
shared store — sidestepping the per-process delta-state problem that breaks
SDK-based metrics under FPM.

## Failure policy

Telemetry never throws into the application. Instrument creation with an
invalid name or conflicting type throws (programmer error, caught in
tests); every *recording* and *export* path is wrapped and reports through
`Telemetry::handleExceptionsUsing()` (default: Laravel's `report()`).
A failing observable-gauge callback drops only its own family, never the
scrape.

## Design decisions

The full reasoning — including the prior-art survey of the PHP telemetry
ecosystem — lives in [`docs/adr`](../adr/0001-runtime-model.md) and
[`docs/research/prior-art.md`](../research/prior-art.md).
