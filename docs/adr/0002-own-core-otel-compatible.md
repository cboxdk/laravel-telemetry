# ADR-0002: Own lightweight core, OTel-compatible at the wire level

- Status: accepted
- Date: 2026-07-02

## Context

Two options were on the table:

- **A. Wrap `open-telemetry/opentelemetry-php`** — inherit spec compliance,
  but its metrics SDK assumes long-running processes and has no good answer
  to shared-nothing PHP state; it also pulls a large dependency tree into
  every consuming app.
- **B. Build our own core** — full control over DX and the Redis-backed
  runtime model (ADR-0001), at the cost of owning the data model.

ADR-0001's requirement (pure PHP, Redis-backed cross-process metrics, no
collector) is exactly the part the OTel SDK does not solve.

## Decision

Build our own core, but stay OTel-compatible where it matters:

1. **Wire format:** the OTLP exporter emits standard OTLP `http/json`.
   Any OTel backend (Grafana, Tempo, Honeycomb, …) works unchanged.
2. **Naming:** OTel semantic conventions are the canonical metric/span
   names (`http.server.duration`, `db.client.query.duration`, …).
   A single optional remap layer may translate names for display;
   there is exactly one internal vocabulary — no `otel|laravel|dash`
   naming modes.
3. **Data model:** span/metric models mirror OTLP field structure so the
   exporter is a serializer, not a translator.

## Validation from prior art (2026-07, see docs/research/prior-art.md)

- **Every dead Laravel Prometheus exporter** (superbalist, triadev, …) died
  from upstream fork churn in the client lib it wrapped (jimdo →
  endclothing → promphp), not from its own code. Owning the core avoids
  that failure mode.
- **The OTel PHP SDK's metrics are effectively broken under FPM**: state is
  aggregated in-process, so each worker exports its own stream; the
  official answer is "run a collector". Our shared-store cumulative export
  solves what the SDK cannot — this is the core differentiator, not just a
  convenience.
- **OTLP/http+json is officially Stable for traces, metrics and logs**, and
  `shish/microotlp` proves SDK-less OTLP from PHP works in practice.
- **spatie/laravel-open-telemetry** pulls the entire OTel SDK for ID
  generation and hex validation (~20 lines to replicate), and despite its
  name emits Zipkin JSON, not OTLP. We take zero OTel dependencies and are
  actually OTLP on the wire.
- **Competitive position**: official `opentelemetry-auto-laravel` requires
  the `ext-opentelemetry` C extension and is traces-only;
  `keepsuit/laravel-opentelemetry` wraps the full SDK. The pitch
  "no extension, no collector, no protobuf — metrics that work under FPM"
  is unoccupied.

## Consequences

- We own: instrument API, registry, Redis storage, OTLP JSON encoding,
  OTLP retry semantics, trace/span ID generation (random 16/8 bytes hex).
- We do NOT own: gRPC, protobuf, context-propagation spec, OTel SDK churn.
- If the OTel PHP SDK's metrics story matures, an adapter exporter can be
  added without touching the public API.
