# Agent guide — cboxdk/laravel-telemetry

Collector-free telemetry for Laravel. Read `llms.txt` for the doc index;
start at `docs/getting-started/api-reference.md` for the public API.

## Commands

```bash
composer check          # pint --test + phpstan (level 8, 1G) + pest — run before every commit
composer test           # pest only
vendor/bin/pest --group=redis   # integration tests (needs local Redis)
php -d apc.enable_cli=1 vendor/bin/pest --group=apcu
```

## Architecture map

- `src/TelemetryManager.php` — the facade target; owns providers, exporters, event buffer, flush
- `src/Metrics/` — Registry (instrument factory, memoized), instruments, `Stores/` (redis/apcu/array/null behind `Contracts\MetricStore`)
- `src/Tracing/` — Tracer (context stack, sample-at-root, capped buffer), Span, W3C `Support\TraceParent`
- `src/Exporters/` — Prometheus renderer (scrape-time), OTLP http/json on raw curl
- `src/Instrumentation/` — queue/query/command hooks; `Http/Middleware/TraceRequest` for requests
- `src/Logging/TelemetryLogHandler.php` — Monolog → OTLP log records
- `src/Testing/TelemetryFake.php` — the `Telemetry::fake()` double

## Invariants — do not break these

1. **Telemetry never throws into the app.** Recording/export paths run
   through `FailSafe::guard`. Only instrument *registration* (bad name,
   type conflict) may throw.
2. **No `KEYS`/`SCAN`** on any Redis path; no full-keyspace `APCuIterator`
   scans. Stores maintain explicit indexes.
3. **Metric state lives in the shared store**, never in the PHP process —
   that is the package's reason to exist (shared-nothing FPM).
4. **Push and pull instruments stay distinct API shapes.** Don't blur
   `gauge('x')->set()` and `gauge('x', fn () => ...)`.
5. **Full W3C traceparent propagation** (trace id AND parent span id) —
   queue payloads, incoming/outgoing HTTP. Children, never detached roots.
6. **Zero cost when disabled**: no listeners registered, no-op instruments,
   no providers booted.
7. **One naming vocabulary**: OTel semantic conventions
   (`[a-z][a-z0-9._]*`). Prometheus names are derived, never stored.
8. OTLP JSON: hex ids, int64 as strings, integer enums, lowerCamelCase.
   Histograms: non-cumulative bucket counts + overflow slot (Prometheus
   renderer accumulates at render time).

Architecture decisions are recorded in `docs/adr/` (runtime model, own
core vs OTel SDK, API shape) with the ecosystem survey in
`docs/research/prior-art.md`. Read the relevant ADR before proposing
structural changes; ADR conclusions are not to be silently reversed.

## Conventions

- PHP ^8.3, `declare(strict_types=1)` everywhere, final classes by default,
  readonly value objects, Pest tests, Larastan level 8.
- New behaviour ships with tests; public API changes ship with docs
  (`docs/`) and, when user-visible, a `CHANGELOG.md` entry and an update to
  `.ai/guidelines/telemetry.blade.php` + `llms.txt`.
- Sibling packages (`~/Projects/laravel-queue-metrics`, `system-metrics`,
  `laravel-ai-assistant`) define the org conventions — check them before
  inventing new structure.
