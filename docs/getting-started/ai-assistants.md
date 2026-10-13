---
title: AI assistants
description: Laravel Boost guideline and skills, agent prompts and llms.txt
weight: 5
---

# AI assistants

The package ships context for AI coding assistants at three levels, so the
telemetry your assistant writes follows the conventions instead of
inventing its own.

## Laravel Boost (recommended)

If the application uses [Laravel Boost](https://github.com/laravel/boost),
`php artisan boost:install` (and `boost:update`) picks up what the package
ships in `resources/boost/`, the path Boost reads in third-party packages:

| File | Loaded | Teaches |
|---|---|---|
| `resources/boost/guidelines/core.md` | upfront, into the app's guideline file | which instrument fits which job, naming and cardinality rules, that telemetry never throws (no defensive try/catch), what is auto-instrumented, `Telemetry::fake()`, the provider pattern for packages |
| `resources/boost/skills/telemetry-instrumentation/SKILL.md` | on demand | adding a metric, span or event: instrument choice, OTel names and units, bounded labels, context dimensions, providers |
| `resources/boost/skills/telemetry-debugging-performance/SKILL.md` | on demand | a slow request or N+1 read from the trace: root-span tallies, N+1 vs duplicate, the app line that ran the query, `X-Telemetry-Label` before/after, `telemetry:watch` |
| `resources/boost/skills/telemetry-testing/SKILL.md` | on demand | `Telemetry::fake()`, every assertion, label-value and cardinality budgets, testing providers and rejected exports |

Three things to know:

- Boost only reads **direct** dependencies. If the app requires
  `cboxdk/laravel-telemetry-ui` but not this package, require this one too
  (`composer require cboxdk/laravel-telemetry`) to get its guideline and
  skills. The UI and insights packages ship their own skills for their MCP
  tools.
- `boost:install` asks which third-party packages' guidelines and skills
  to install: tick `cboxdk/laravel-telemetry`. Boost remembers the choice
  in `boost.json`, so `boost:update` refreshes them after an upgrade.
- Skills need Boost 2.x and an agent that supports them; pick "Agent
  Skills" among the features. Boost copies each one into the agent's skills
  directory (for Claude Code, `.claude/skills/`).

## Copy-paste agent prompts

Several documentation pages end with an **Agent prompt** block — a
self-contained task you can paste straight into your assistant:

| Task | Where |
|---|---|
| Install & configure the package | [Installation](installation.md#agent-prompt) |
| Instrument the app's key flows | [Quickstart](../quickstart.md#agent-prompt) |
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

`resources/boost/`, `llms.txt` and `AGENTS.md` are part of the public
surface: PRs that change the API must update them (see CONTRIBUTING).
`tests/Feature/BoostResourcesTest.php` checks that every skill parses the
way Boost parses it, that none of it is left out of the dist archive, and
that every `Telemetry::` method, fake assertion and `telemetry:` command
the files name exists.
