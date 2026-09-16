---
title: 0002 — Who owns a native unit of work
description: Why the outermost unit wins, why workers open none, and why two profilers never run together
weight: 2
---

# 0002 — Who owns a native unit of work

**Status:** accepted, 2026-09-16.

## Context

[`cboxdk/telemetry-native`][native] measures one *unit of work* at a time:
`begin()` opens it, `finish()` closes it and returns what it measured. The
extension deliberately holds no opinion about what a unit is — a unit is a
label and a trace id, and any Laravel knowledge would be knowledge in C.

Two properties of that API decide everything on this side of the boundary.

**Units do not nest.** A second `begin()` inside an open unit abandons the
first one, silently, along with its samples; the abandoned handle then
returns an empty array. The extension is explicit that a caller nesting
units is a bug in the caller.

**A unit is opened per process, not per process lifetime.** `RINIT` fires
once per request under FPM and once per *process* in a worker, which is why
`cbox_telemetry.auto` exists and why it is off by default.

Laravel violates the first property without being asked to. A sync job
dispatched inside a request is a queue unit inside an HTTP unit. A scheduled
task is a unit inside `schedule:run`. Every job is a unit inside
`queue:work`. Three of the four places this package brackets a unit can
contain another one.

Nesting had to be resolved by someone. Leaving it to the extension means the
outer unit is abandoned — and the outer unit is the request, whose samples
cover the work the inner one caused.

## Decision

**1. The outermost unit wins.** `NativeProfiler` holds one open unit per
process and refuses a second `begin()` while it is open. The inner site gets
null and records nothing native; its span, metrics and resource measurements
are unaffected.

This is a refusal, not a queue: an inner unit that "waits" would measure a
period that had already ended.

**2. The outermost unit is not always the outermost call.** A command that
runs for hours and hosts its own units — `queue:work`, `horizon*`,
`octane:*`, `schedule:run`, `schedule:work`, `reverb:*` — opens no unit at
all, so the jobs and tasks inside it can. The list is configuration
(`native.exclude_commands`), because which commands a given app treats as
daemons is the app's knowledge, not this package's.

That leaves a command's own bootstrap unmeasured in exactly the cases where
measuring it would have meant measuring nothing else. It is the right trade
even though it is a real loss: a `queue:work` unit would report one number
covering a thousand jobs.

**3. The latch is released on every reset path.** `NativeProfiler`
implements `ManagesRequestState`, so an Octane or NativePHP request that
dies with a unit open closes it and discards the result. Without that, one
failed request would hold the one-unit rule shut for the remaining life of
the worker — a bug that would look like "native telemetry stopped working
on this one box".

**4. Never two profilers at once.** Where both `cbox_telemetry` and
`ext-excimer` are installed, the native profiler runs and excimer does not.
Two statistical samplers running concurrently spend a meaningful share of
their samples observing each other, and neither profile is then a
description of the application. Excimer remains the fallback, unchanged,
for hosts without the extension.

**5. The unit decides its own duration.** The tail threshold that keeps or
discards a profile (`profiling.min_duration_ms`) is evaluated against the
unit's own monotonic clock, at `finish()`, while the span is still open —
not against `Span::durationMs()`, which has no value until the span ends.
Ending the span first would answer the question correctly and have nothing
left to attach the answer to.

## Consequences

- A sync job inside a request contributes to the request's native
  measurements rather than getting its own. That is the accurate reading:
  the work happened inside the request.
- An app that runs a custom worker command has to add it to
  `exclude_commands` or that command will open one very long unit. The
  extension's own `auto_max_ms` bounds the damage; it does not fix it.
- `cbox_telemetry.auto=1` remains an FPM-only setting, and `telemetry:doctor`
  reports it rather than trying to infer whether it is right — the SAPI a
  php.ini serves is not visible from a console command.

[native]: https://github.com/cboxdk/telemetry-native
