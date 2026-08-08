---
title: Decisions
description: Architecture decision records — the reasoning behind the invariants.
weight: 7
---

# Decisions

Architecture decision records. Each one states a decision that constrains
the package, the context that forced it, and what it costs. The invariants
in `AGENTS.md` are the short form; these are the reasoning.

- **[0001 — Metric state on single-process runtimes](0001-metric-state-on-single-process-runtimes.md)** — why invariant #3 is about *shared writers*, not about avoiding process memory, and why NativePHP targets get a SQLite store
