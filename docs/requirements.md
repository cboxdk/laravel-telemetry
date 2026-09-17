---
title: Requirements
description: Runtime and framework versions cboxdk/laravel-telemetry needs
weight: 3
---

# Requirements

Taken directly from the package's `composer.json` — the resolver enforces them,
so this page only explains them.

## Runtime

| Requirement | Version | Why |
|---|---|---|
| PHP | `^8.3 \|\| ^8.4 \|\| ^8.5` | Uses modern PHP language features throughout. |

No non-default PHP extensions are required. Metric aggregation uses a shared
store (Redis by default, APCu supported); OTLP export posts over HTTP using
PHP's `curl` extension directly — no HTTP-client library dependency.
`ext-curl` is only needed when OTLP export is enabled, and it is
default-enabled in virtually all PHP builds. Neither path needs an OTel SDK,
a protobuf extension, or a sidecar.

## Framework

| Requirement | Version |
|---|---|
| Laravel (`illuminate/contracts`, `illuminate/support`) | `^12.0 \|\| ^13.0` |

Registered via package auto-discovery — no manual provider wiring.

## Optional extensions

None of these are required, and none of them are dependencies — each is
`extension_loaded()`-guarded, and its absence is a silent no-op.

| Extension | What it adds |
|---|---|
| [`cbox_telemetry`](production/native-runtime.md) | CPU profiles with a confidence figure, exact `pdo.connect`/`curl.exec` timing, GC counters, and crash records correlated to the trace the process died in. `pie install cboxdk/telemetry-native` |
| `excimer` | The fallback CPU profiler, profile only. Used when `cbox_telemetry` is not installed |
| `apcu` | Single-host metric store, no Redis |
| `pdo_sqlite` | The sqlite metric store and OTLP spool (NativePHP mobile/desktop) |
| `redis` | The default metric store (phpredis) |
