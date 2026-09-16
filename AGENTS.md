# Agent guide — cboxdk/laravel-telemetry

Collector-free telemetry for Laravel. Read `llms.txt` for the doc index;
start at `docs/getting-started/api-reference.md` for the public API.

## Commands

```bash
composer check          # pint --test + phpstan (level 9, 1G) + pest — run before every commit
composer test           # pest only (excludes --group=benchmark)
vendor/bin/pest --group=redis   # integration tests (needs local Redis)
php -d apc.enable_cli=1 vendor/bin/pest --group=apcu
vendor/bin/pest --group=benchmark   # overhead benchmark — see docs/production/performance.md
```

## Architecture map

- `src/TelemetryManager.php` — the facade target; owns providers, exporters, event buffer, flush
- `src/Metrics/` — Registry (instrument factory, memoized), instruments, `Stores/` (redis/apcu/array/null behind `Contracts\MetricStore`)
- `src/Tracing/` — Tracer (context stack, sample-at-root, capped buffer), Span, W3C `Support\TraceParent`
- `src/Exporters/` — Prometheus renderer (scrape-time), OTLP http/json on raw curl
- `src/Instrumentation/` — queue/query/command hooks; `Http/Middleware/TraceRequest` for requests
- `src/Logging/TelemetryLogHandler.php` — Monolog → OTLP log records
- `src/Native/` — the optional `cbox_telemetry` extension (cboxdk/telemetry-native):
  runtime seam (`Contracts\NativeRuntime`), unit-of-work bracketing, result parsing,
  reporting, crash drain. Units never nest — `NativeProfiler` enforces it
- `src/Testing/TelemetryFake.php` — the `Telemetry::fake()` double

## Invariants — do not break these

1. **Telemetry never throws into the app.** Recording/export paths run
   through `FailSafe::guard`. Only instrument *registration* (bad name,
   type conflict) may throw.
2. **No `KEYS`/`SCAN`** on any Redis path; no full-keyspace `APCuIterator`
   scans. Stores maintain explicit indexes.
3. **Metric state lives in a store shared by every process that writes the
   series**, never only in one request's memory — that is the package's
   reason to exist (shared-nothing FPM). On a single-writer runtime a
   process-local store satisfies this; see
   `docs/decisions/0001-metric-state-on-single-process-runtimes.md`.
4. **Push and pull instruments stay distinct API shapes.** Don't blur
   `gauge('x')->set()` and `gauge('x', fn () => ...)`.
5. **Full W3C traceparent propagation** (trace id AND parent span id) —
   queue payloads, incoming/outgoing HTTP. Children, never detached roots.
6. **Zero cost when disabled**: no listeners registered, no-op instruments,
   no providers booted.
7. **One naming vocabulary**: OTel semantic conventions
   (`[a-z][a-z0-9._]*`). Prometheus names are derived, never stored.
8. **A native unit of work never nests.** `cbox_telemetry` abandons the
   outer unit on a second `begin()`, so `Native\NativeProfiler` refuses one:
   the outermost wins, and commands that host their own units (`queue:work`,
   `schedule:run`, …) open none. Never run the native profiler and excimer
   together. See `docs/decisions/0002-native-unit-of-work-ownership.md`.
9. OTLP JSON: hex ids, int64 as strings, integer enums, lowerCamelCase.
   Histograms: non-cumulative bucket counts + overflow slot (Prometheus
   renderer accumulates at render time).

## Conventions

- PHP ^8.3, `declare(strict_types=1)` everywhere, final classes by default,
  readonly value objects, Pest tests, Larastan level 9. Narrow `mixed`
  values (config reads, framework interfaces typed `mixed`) through
  `Support\Cast` rather than a raw `(string)`/`(int)`/`(float)` cast.
- New behaviour ships with tests; public API changes ship with docs
  (`docs/`) and, when user-visible, a `CHANGELOG.md` entry and an update to
  `.ai/guidelines/telemetry.blade.php` + `llms.txt`.
- Sibling packages (`cboxdk/system-metrics`, `cboxdk/laravel-queue-metrics`)
  define the org conventions — follow their structure before inventing new
  patterns.
