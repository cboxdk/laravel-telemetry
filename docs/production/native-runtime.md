---
title: Native runtime
description: The optional cbox_telemetry extension — CPU profiles, connection timing and crash records
weight: 9
---

# Native runtime (cboxdk/telemetry-native)

This package can tell you a request took 1,420 ms and burned 410 ms of PHP
CPU. It cannot tell you *which call stacks* burned it, why opening the
database connection took 180 ms, or what the process was doing when it
segfaulted. Nothing in PHP can: a segfault takes the shutdown functions with
it, and a connect() is spent in DNS, TCP and TLS where no PHP profiler is
looking.

[`cboxdk/telemetry-native`][native] is an optional PHP extension that
measures exactly those three things. It is a companion, not a replacement:
the extension observes the runtime and nothing else — it knows no Laravel,
attaches no semantics, and never touches the network. Naming, sampling
policy, redaction and export all stay here.

```bash
pie install cboxdk/telemetry-native
```

Without it, everything on this page is a silent no-op and profiling falls
back to `ext-excimer` if that is installed. `telemetry:doctor` says which.

## What it adds

| | |
|---|---|
| **CPU profile** | A statistical profile per slow request/job, aggregated to top functions — with the sampling period, clock and confidence attached |
| **Native operation timing** | Exact begin/end for `pdo.connect`, `redis.connect`, `redis.pconnect`, `curl.exec` and (opt-in) `stream.connect` |
| **Runtime counters** | GC runs and objects collected, per unit of work |
| **Crash records** | What PHP was doing when the process died on a fatal signal, correlated to the trace it died in |

## Units of work

The extension measures one *unit of work* at a time — a request, a job, a
command, a scheduled task. This package opens and closes them for you:

| unit | opened by | closed |
|---|---|---|
| `http` | `TraceRequest` middleware | `terminate()`, before the span ends |
| `queue` | `JobProcessing` (never for `sync`) | `JobProcessed`/`JobFailed` |
| `command` | `CommandStarting` | `CommandFinished` |
| `schedule` | `ScheduledTaskStarting` | task finished/failed |

**Units do not nest.** A second `begin()` inside an open one abandons the
first, silently and with its samples. Laravel makes that easy to trigger by
accident — a sync job dispatched inside a request, a task inside
`schedule:run` — so the outermost unit wins and the inner site opens
nothing.

Which unit deserves to be outermost is not always the outermost *call*.
`queue:work` runs for hours; a unit covering it would swallow every job
inside it and describe none of them. Those commands are listed in
`telemetry.native.exclude_commands`, open no unit at all, and leave the
boundary to what runs within them:

```php
'exclude_commands' => ['queue:work', 'queue:listen', 'horizon*', 'octane:*', 'schedule:run', 'schedule:work', 'reverb:*'],
```

## `cbox_telemetry.auto` — right for FPM, wrong for workers

With `cbox_telemetry.auto=1` the extension opens a unit at `RINIT`, before
any PHP runs, and this package's `begin()` *adopts* it rather than starting
over. That is worth having under FPM: the profile then covers autoloading,
service providers and config loading — the part of a cold request no
middleware can see, and often most of it.

It is wrong everywhere a process serves more than one unit. `RINIT` fires
once per *process* in a queue worker or an Octane server, so an automatic
unit there would cover hours. Set `auto=0` in any php.ini that serves
daemons.

## What you get back

**On the unit's span** — the operation aggregates and the GC counters:

```
pdo.connect.count        1
pdo.connect.duration_ms  8.3
pdo.connect.max_ms       8.3
curl.exec.count          2
curl.exec.duration_ms    29.0
php.gc.runs              3
php.gc.collected         128
```

**As metrics** — because traces are sampled and a connect-time regression
has to be visible without one:

| metric | labels | |
|---|---|---|
| `runtime.operations` | `operation`, `unit` | Calls by operation type |
| `runtime.operation.duration` | `operation`, `unit` | Time one unit spent inside that operation (ms) |
| `runtime.gc.runs` / `runtime.gc.collected` | `unit` | Cycle-collector pressure |
| `runtime.crashes` | `signal`, `unit` | Processes that died on a fatal signal |

The labels are deliberately not the route or job name — the span already
has those, and a per-route breakdown of connect time is a cardinality bill
for a question you can answer from the trace.

**As a `profile.captured` event**, for units slower than
`profiling.min_duration_ms` (the same tail threshold the excimer path
uses):

```
profile.source          native
profile.sample_count    814
profile.period_ns       1000000
profile.clock           cpu
profile.confidence      0.9807
profile.dropped         2
profile.timer_overruns  14
profile.top_functions   [{"function":"App\\Services\\Pricing::calculate","file":"…","line":82,"samples":612}, …]
```

`confidence` is the number to read before acting on the rest. Samples that
could not be taken at a safe point (`dropped`) and ticks the kernel never
delivered (`timer_overruns`) are counted separately, so you can tell "94% of
this was sampled directly" from "most of this is arithmetic because the
period is finer than the kernel can deliver". A `HZ=250` kernel — the
Debian/Ubuntu generic default — cannot deliver a 1 ms period at all, and
will say so here rather than quietly inventing one.

Profiling always runs and the result is usually thrown away. That is the
cheap arrangement, not the wasteful one: the sampler's cost is roughly
constant, a unit that turns out to be fast resets the native state without
allocating anything into PHP, and deciding *afterwards* whether a unit was
interesting requires no prediction.

### The full call tree

`telemetry.native.stacks` adds `profile.stacks` (flat `[parent, frame,
samples]` triples) and the `profile.frames` table they resolve against. It
is one to two orders of magnitude more data per slow request than the
top-function list, which is why it is off by default;
`max_stack_nodes` caps it. Truncation keeps emission order, so every
surviving node still has its parent.

## Crash records

When a process dies below the level PHP's exception handler can see, the
extension's signal handler writes one small record — signal, pid, the unit
that was open, the operation in flight, a ring of breadcrumbs, and the trace
and span id it was carrying. Then it restores the previous handler and lets
the process die exactly as it would have, core dump and all.

Records outlive the process that wrote them, so something else has to
collect them. `telemetry:flush` does, on whatever schedule you already run
it:

```bash
php artisan telemetry:flush        # drains, reports, exports
php artisan telemetry:crashes      # the same thing, by hand, with output
```

Each record becomes a FATAL-severity `crash.recorded` event **in the trace
the process died in** — so a segfault appears on the Tempo waterfall for the
request that caused it, next to the operation that was open at the time.

Draining consumes. Both commands are safe to run, but a record reported by
one is not reported again by the other.

If you scrape Prometheus and never run `telemetry:flush`, nothing drains the
sink and `runtime.crashes` stays at zero — schedule the flush, or run
`telemetry:crashes` from cron:

```php
Schedule::command('telemetry:flush')->everyMinute()->onOneServer();
```

## Configuration

| Key | Env | Default |
|---|---|---|
| `native.enabled` | `TELEMETRY_NATIVE` | `true` — master switch; off means no unit is ever opened |
| `native.profile` | `TELEMETRY_NATIVE_PROFILE` | `true` — use the native profiler (replaces ext-excimer where both exist) |
| `native.period_us` | `TELEMETRY_NATIVE_PERIOD_US` | `null` — leaves the extension's INI period (1000 µs) alone |
| `native.max_depth` | `TELEMETRY_NATIVE_MAX_DEPTH` | `null` — leaves the extension's INI depth (64 frames) alone |
| `native.stacks` | `TELEMETRY_NATIVE_STACKS` | `false` — the full call tree alongside the top functions |
| `native.max_stack_nodes` | `TELEMETRY_NATIVE_MAX_STACK_NODES` | `2048` |
| `native.operations` | `TELEMETRY_NATIVE_OPERATIONS` | `true` — operation attributes + metrics |
| `native.counters` | `TELEMETRY_NATIVE_COUNTERS` | `true` — GC counters |
| `native.crashes` | `TELEMETRY_NATIVE_CRASHES` | `true` — drain and report crash records |
| `native.crash_max` | `TELEMETRY_NATIVE_CRASH_MAX` | `32` records per drain |
| `native.crash_breadcrumbs` | `TELEMETRY_NATIVE_CRASH_BREADCRUMBS` | `32` — breadcrumbs kept per record |
| `native.exclude_commands` | — | Workers, schedulers and application servers — matched with `Str::is()` |

Two layers decide two different things, and they can disagree in silence:
the extension's INI decides what *exists* (which hooks are installed, what
the crash recorder is doing), this config decides what is *used*. A hook
disabled in php.ini simply never produces aggregates. `telemetry:doctor`
reports both:

```
Native runtime ................ OK — cbox_telemetry 0.1.0
  profiler .................... ready — posix, CPU time, period 1000 µs
  operation hooks ............. pdo, curl (unavailable: redis)
  crash recorder .............. armed — /tmp/cbox-telemetry/33/crash-4711.bin
```

## Testing without the extension

The extension is optional, which means "without it" is a code path your
tests have to cover — and "with it" is one they cannot cover on a runner
that does not have it installed. Bind the fake for either:

```php
use Cbox\Telemetry\Contracts\NativeRuntime;
use Cbox\Telemetry\Testing\FakeNativeRuntime;

$this->app->instance(NativeRuntime::class, FakeNativeRuntime::withProfile());
$this->app->instance(NativeRuntime::class, new FakeNativeRuntime(available: false));
```

It records every `begin()` context and every `finish()` call, and keeps the
one contract detail call sites depend on: an unknown handle returns an empty
array, not a unit that took 0 ms.

## Overhead

The extension's own measurements, under concurrent FPM on the Cbox
production image: loading it, installing the hooks and arming the crash
recorder cost nothing that harness can resolve, and profiling every request
costs somewhere between nothing and about 5%. Differences under ~6% are not
meaningful there — the repeated-baseline row in the extension's README says
so explicitly. Regenerate on your own hardware before quoting a number in a
capacity plan.

[native]: https://github.com/cboxdk/telemetry-native
