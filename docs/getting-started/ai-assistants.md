---
title: AI assistants
description: Ship-ready guidance for Claude Code, Cursor, Copilot & Laravel Boost
weight: 5
---

# AI assistants

The package ships context for AI coding assistants at three levels, so the
telemetry your assistant writes follows the conventions instead of
inventing its own.

## Laravel Boost (recommended)

If the application uses [Laravel Boost](https://github.com/laravel/boost),
our guidelines are picked up automatically: the package ships
`.ai/guidelines/telemetry.blade.php`, which `php artisan boost:install`
composes into the application's AI context alongside the framework
guidelines.

The guideline teaches the assistant: which instrument fits which job,
naming and cardinality rules, that telemetry never throws (no defensive
try/catch), auto-instrumentation boundaries (don't hand-roll request/job
spans), `Telemetry::fake()` in tests, and the provider pattern for
packages.

## Copy-paste agent prompts

Several documentation pages end with an **Agent prompt** block — a
self-contained task you can paste straight into your assistant:

| Task | Where |
|---|---|
| Install & configure the package | [Installation](installation.md#agent-prompt) |
| Instrument the app's key flows | [Quickstart](quickstart.md#agent-prompt) |
| Wire the log channel into the stack | [Logs](../core-concepts/logs.md#agent-prompt) |
| Add telemetry to your own package | [Providers](../extension-points/providers.md#agent-prompt) |
| Connect to the Grafana stack | [Grafana stack](../production/grafana-stack.md#agent-prompt) |

Each prompt encodes the guardrails (bounded labels, no defensive
try/catch, don't re-instrument what's automatic, verify with tests) so
the result follows the conventions even if the assistant never reads the
rest of the docs.

## llms.txt

The repo root contains an [`llms.txt`](../../llms.txt) index mapping every
documentation page with a one-line description. Point any assistant at it
for retrieval:

```text
Read vendor/cboxdk/laravel-telemetry/llms.txt and follow links as needed.
```

## Working on this package itself

Contributors get `AGENTS.md` (mirrored by `CLAUDE.md`) at the repo root:
commands, architecture map and the eight invariants (never-throw,
no-KEYS/SCAN, shared-store state, distinct push/pull shapes, full
traceparent propagation, zero-cost-disabled, one naming vocabulary, OTLP
JSON rules) plus pointers to the ADRs.

## Keeping it honest

`.ai/guidelines/`, `llms.txt` and `AGENTS.md` are part of the public
surface: PRs that change the API must update them (see CONTRIBUTING).
