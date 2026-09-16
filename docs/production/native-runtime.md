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

The tail threshold counts the adopted time too: a unit that spent 800 ms in
the bootstrap and 10 ms in routing is an 810 ms unit, and keeps its profile
under the default 500 ms. Timing it from the adoption would have thrown away
precisely the profiles automatic mode exists to collect. The adopted time is
measured from the SAPI's request start, capped at the extension's own
`auto_max_ms` — a bootstrap that blows through that deadline stops sampling
but keeps what it collected (`profile.capped`), so it counts as at least
that slow rather than as nothing.

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
profile.confidence      0.9804
profile.dropped         2
profile.timer_overruns  14
profile.deferred_samples 0
profile.top_functions   [{"function":"App\\Services\\Pricing::calculate","file":"…","line":82,"samples":612}, …]
```

`confidence` is the number to read before acting on the rest. It is the
share of this profile that means what it appears to mean, and the
arithmetic is worth knowing because it is easy to get wrong in the
flattering direction: a sample carries the **weight** of every tick it
accounts for, so `sample_count` is ticks, not stack walks. Of the ticks
accounted for (`sample_count + dropped`):

- `timer_overruns` were never delivered — nothing was observed, and their
  weight landed on whichever stack was walked next;
- `deferred_samples` were delivered late, because the VM was somewhere it
  could not be interrupted — real observations, booked next door;
- `dropped` were observed but had nowhere to go (frame table, trie or arena
  full).

It is a floor rather than an exact fraction, because those counters can
overlap — a dropped stack walk takes its whole weight with it, overruns
included, and the aggregates cannot say how much. The error is always in
the direction of understating a profile. So you can tell "98% of this was sampled where it says" from "most of this
is arithmetic because the period is finer than the kernel can deliver". A
`HZ=250` kernel — the Debian/Ubuntu generic default — cannot deliver a 1 ms
period at all, and will say so here rather than quietly inventing one.

Profiling always runs and the result is usually thrown away. That is the
cheap arrangement, not the wasteful one: the sampler's cost is roughly
constant, a unit that turns out to be fast resets the native state without
materialising the profile — the aggregates and counters are built either
way, the frame table and call tree are not — and deciding *afterwards*
whether a unit was interesting requires no prediction.

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

Each record becomes a FATAL-severity `crash.recorded` event carrying the
trace and span id the process died in. Events are exported as OTLP **log
records**, not spans — so the crash shows up in Loki filtered to that trace
id, and in Grafana's "logs for this trace" view next to the request that
caused it, carrying the operation that was open at the time. It does not
add a bar to the Tempo waterfall, and the request span itself may never have
been exported at all: the process died before terminate.

Draining consumes. Both commands are safe to run, but a record reported by
one is not reported again by the other.

Three things decide whether a record is ever collected, and all three are
easy to get wrong:

**Every host has its own sink.** Records are files on the machine that
crashed, so `onOneServer()` — right for the metric flush — collects only the
winner's records. Give crash draining its own per-host schedule:

```php
Schedule::command('telemetry:flush')->everyMinute()->onOneServer();
Schedule::command('telemetry:crashes')->everyFiveMinutes();   // every host
```

**Every uid has its own sink, and only that uid can drain it.** The
extension appends the effective uid to `crash.dir` as a private `0700`
subdirectory, and a drain reads only its own uid's directory — a different
`crash.dir` does not change that, it only moves the base. So a scheduler
running as `deploy` cannot collect the records of an FPM pool running as
`www-data`, whatever the paths say. Run the drain **as the user that wrote
the records**: a `sudo -u www-data php artisan telemetry:crashes` cron entry,
or one drain per pool user.

**Something has to run.** If you scrape Prometheus and never run either
command, nothing drains the sink and `runtime.crashes` stays at zero while
records pile up.

With telemetry disabled (`TELEMETRY_ENABLED=false`) neither command drains
anything: consuming a record and handing it to an exporter that is not there
would destroy the only artefact the dead process left.

## Configuration

| Key | Env | Default |
|---|---|---|
| `native.enabled` | `TELEMETRY_NATIVE` | `true` — master switch; off means no unit is ever opened |
| `native.profile` | `TELEMETRY_NATIVE_PROFILE` | `true` — use the native profiler. Where both extensions are installed, excimer runs only when the native sampler does not — including on a host where the extension is loaded but has no usable timer |
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
