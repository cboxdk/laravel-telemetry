# Upgrading

## 2.x → 3.0

Three changes need your hands on a dashboard. Nothing else in this guide
is urgent, and nothing in it is automatic: a unit change is invisible at
runtime — a panel reading milliseconds off a seconds histogram shows 250
where the truth is 0.25 and says nothing about it.

Work through the three sections below **before** you deploy, because the
old and new values land in the same series name.

### 1. Durations are seconds

Every duration histogram this package emits was milliseconds under a name
that OpenTelemetry defines as seconds. They are now seconds, and the
buckets moved with them.

```
checkout.duration                 queue.job.duration
command.duration                  runtime.operation.duration
db.migration.duration             schedule.task.duration
http.client.connection.duration   screen.interaction.duration
http.client.request.duration      screen.view.duration
http.server.request.duration      telemetry.export.duration
```

In every panel, alert and recording rule that reads one of these:

- **thresholds divide by 1000** — `> 500` becomes `> 0.5`;
- **axis units change** from milliseconds to seconds;
- **`le` bucket boundaries change**, so any query naming a bucket
  explicitly (`http_server_request_duration_bucket{le="100"}`) needs the
  new boundary.

The bundled Grafana dashboards and the bundled alert rules are already
updated. If you copied them, re-copy them.

**During the deploy the series holds both.** A histogram cannot be
rescaled retroactively, so a graph spanning the cutover shows a cliff.
Two honest options: accept the cliff and annotate it, or rename the
metric for the new scale and keep the old one until its retention expires
(`Telemetry::histogram('http.server.request.duration.seconds', …)` in a
wrapper) — the second costs you a migration of the same panels later, so
most people should take the cliff.

### 2. Attribute keys on system metrics

| was | is |
|---|---|
| `state` on `system.memory.*` | `system.memory.state` |
| `state` on `system.filesystem.*` | `system.filesystem.state` |
| `direction` on `network.io.*` | `network.io.direction` |

Both producers changed together — the metrics provider and the
`telemetry:monitor` command, which emitted these independently.

Any query grouping or filtering on the bare key needs the qualified one:
`sum by (state)` becomes `sum by (system_memory_state)`.

### 3. Two instrument types, and a `_ratio` suffix

`system.memory.usage` and `system.filesystem.usage` are UpDownCounters
rather than gauges. In Prometheus they still render as gauges, so most
queries are unaffected — but they are now legitimately summable across
hosts, which is what they always should have been.

A **dimensionless gauge** now carries the `_ratio` suffix the
OTLP-to-Prometheus translation gives it. If you declared your own gauge
with `unit: '1'`, its Prometheus name gains `_ratio`. Two of this
package's own metrics were relabelled instead, because they were using
`1` for something that is not a ratio: a load average is a run-queue
length, and a circuit-breaker flag is a state.

### 4. If you customised redaction

`telemetry.redaction.keys` and `.patterns` still work and still mean what
they meant. What changed is that they are no longer the whole story: a
second half now matches the VALUE's shape, so a credential in a parameter
nobody listed is redacted anyway.

Two consequences worth knowing:

- **More is redacted than before.** If a value you rely on reading is now
  `[REDACTED]`, the shape rule caught it. `telemetry.redaction.allow` is
  the exception list.
- **`Redactor` implements `RedactsTelemetry`,** and binding that contract
  replaces the engine entirely — for an organisation whose redaction
  policy is its own. The contract gained `value()` since 2.x, so an
  existing implementation needs that method.

Optional PII scrubbing (email, IBAN, card, CPR, IPv6, phone) is off by
default. Turn it on with `telemetry.redaction.pii.detectors`.

### 5. Nothing else needs doing

The rest of 3.0 is hardening — fail-safety, memory ceilings, cardinality
budgets, atomic store writes. It changes no name and no unit, and needs no
configuration. `php artisan telemetry:doctor` after the deploy will tell
you what it can see, including whether APCu is available for the circuit
breaker's cooldown (without it the breaker is per-request under PHP-FPM).
