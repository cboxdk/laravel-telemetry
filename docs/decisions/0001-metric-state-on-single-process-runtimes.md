---
title: 0001 — Metric state on single-process runtimes
description: Why invariant #3 is about shared writers, not about avoiding process memory
weight: 1
---

# 0001 — Metric state on single-process runtimes

**Status:** accepted, 2026-07-30.

## Context

Invariant #3 has always read: *metric state lives in the shared store,
never in the PHP process — that is the package's reason to exist
(shared-nothing FPM).*

Under FPM that rule is exactly right and load-bearing. Ten workers each
holding their own counter produce ten wrong numbers; only an atomic
increment against Redis or APCu produces one right one.

Two runtimes break the assumption behind the wording.

**NativePHP for Mobile v4.** PHP is embedded in the app process. There is
one process, one user, one device, and neither Redis nor APCu exists. A
"shared" store has nothing to share with. The runtime is also not
request-shaped: `NativeRouter::start()` enters a loop that calls
`$component->runLoop()`, and that loop holds a single request open for the
entire lifetime of a screen, blocking between user interactions. So the
per-request boundary the package flushes on does not occur while a user
sits on a screen.

**NativePHP for Desktop v2.** An embedded PHP server behind Electron. Here
there genuinely are several processes — the server plus any queue worker
or scheduler the app spawns — so the shared-writer problem is real again.
But Redis and APCu are still absent by default.

Read literally, invariant #3 forbids both targets. Read for its intent, it
forbids neither.

## Decision

**1. Restate invariant #3 in terms of writers.** Metric state must live in
a store shared by every process that writes the same series, and must
never live only in the memory of a single request. Where exactly one
process writes a series, a process-local store satisfies that
requirement trivially rather than violating it.

The rule was never "avoid process memory". It was "one series, one
aggregation point". FPM makes those look like the same rule; a
single-process runtime pulls them apart.

**2. Add a `sqlite` store driver**, and make it the default for both
NativePHP targets. SQLite is the only option that answers both targets
with one implementation:

- durable across app restarts, which the mobile target needs because a
  backgrounded app can be killed at any moment;
- multi-process safe under WAL, which the desktop target needs.

It is subject to the same constraints as every other store: atomic per
write, explicit indexes, no full-keyspace scans (invariant #2).

**3. `array` stays unsupported in production on both targets.** It loses
everything on app kill — the exact situation you most want the metrics
for — and it is simply incorrect on desktop, where a queue worker writes
the same series from another process.

**4. No public API change.** Driver selection stays in config. Nothing in
the instrument API distinguishes a device from a web node.

## Consequences

Both NativePHP targets become viable, and single-node deployments with no
Redis get a store that is correct rather than merely convenient.

The cost is a third store implementation to keep in step with the
`MetricStore` contract, and write throughput well below a Redis pipeline —
so the NativePHP targets should sit behind `BufferedMetricStore` rather
than writing through on every observation.

Nothing changes for FPM users. `redis` remains the default and remains the
only sensible answer for a multi-node deployment.

## Alternatives rejected

**Array store flushed at shutdown.** Loses every metric recorded in a
session that crashed. Metrics whose failure mode is "absent precisely when
something went wrong" are worse than no metrics.

**Drop push instruments on device; ship observations as OTLP events.**
Would blur the push/pull instrument shapes that invariant #4 keeps
distinct, and moves aggregation to the backend for one target only.

**A file store with `flock`.** This is SQLite with the durability and
concurrency bugs still ahead of us.
