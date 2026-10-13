---
title: "Cookbook: Scheduled task monitoring"
description: Missed, late, overrunning and failing scheduled tasks, and a scheduler that stopped
weight: 3
---

# Cookbook: Scheduled task monitoring

Spans, `schedule.task.duration` and the `schedule.tasks.{processed,failed,skipped}`
counters come free with `instrument.scheduled_tasks`. They describe the runs
that happened. The failures that hurt most are the runs that did not:
the cron entry that went missing in a server move, the `schedule:work`
process nobody restarted, the nightly export that has been hanging since
Tuesday. No event fires for those, so nothing counts them.

`instrument.schedule_monitoring` (default on) closes that gap with a few
gauges, and the bundled alert rules compare them.

## What is recorded

| Gauge (`task` label) | Set when | Prometheus name |
|---|---|---|
| `schedule.run.last_seen` (no task label) | `schedule:run` starts | `schedule_run_last_seen_seconds` |
| `schedule.task.previous_due` | `schedule:run` starts: the last time the task's cron matched, in its own timezone | `schedule_task_previous_due_seconds` |
| `schedule.task.listed` | `schedule:run` starts: the task is still in the schedule | `schedule_task_listed_seconds` |
| `schedule.task.interval` | `schedule:run` starts: seconds between the task's current and next cron match (a weekday task's Friday interval is three days) | `schedule_task_interval_seconds` |
| `schedule.task.last_seen` | the task starts **or is skipped** by a filter or overlap lock | `schedule_task_last_seen_seconds` |
| `schedule.task.running_since` | the task starts — written to the store at once, so a task that hangs or is killed is still seen running; back to `0` when it finishes or fails | `schedule_task_running_since_seconds` |
| `schedule.task.last_succeeded` | the task finishes successfully | `schedule_task_last_succeeded_seconds` |

Plus a `schedule.task.start_delay` histogram: seconds from due to started.
`schedule:run` starts tasks one after another, so a long task makes the
ones behind it late; this is where that shows. Sub-minute tasks
(`everyTenSeconds()`) are left out — their offset in the minute is their
schedule. And `schedule:run` creates each listed task's
`schedule.tasks.{processed,failed}` series at zero, so a task's very first
failure is an increase Prometheus can see.

All values are unix timestamps in seconds. Background tasks
(`runInBackground()`) are covered: their start is seen by `schedule:run`,
their finish by `schedule:finish`.

Tasks limited with `environments()` are only published where they run,
and tasks held by maintenance mode are left out while the app is down.
Tasks sharing a label (see the bounded `task` name in
`ScheduleInstrumentation::taskName()`) share one series.

## The alerts

From `resources/grafana/alerts/telemetry-alerts.yaml`, group
`telemetry-background`:

| Alert | Fires when |
|---|---|
| `SchedulerNotRunning` (critical) | `schedule:run` has not started for 3 minutes |
| `ScheduledTaskMissed` | a task was due more than 5 minutes ago and has not started or been skipped since — or, for a task due every few minutes, has not been seen for its interval plus 5 minutes — and is still in the schedule. A task started late behind a long one is not missed |
| `ScheduledTaskOverrunning` | a run started over an hour ago and has not finished. A process that died mid-task never clears `running_since`, so this catches those too |
| `ScheduledTasksStartingLate` | p95 start delay over 2 minutes |
| `ScheduledTaskFailing` | a task run failed in the last 15 minutes |

Hosts are aggregated with `max`, so an `onOneServer()` task is not
"missed" by the servers that lost the lock. Tune the overrun threshold per
task with a `task=~"..."` matcher where an hour is normal.

```promql
# seconds since each task was last started or skipped
time() - max by (task) (schedule_task_last_seen_seconds)
```

The alert rules have promtool tests — `promtool test rules
tests/alerts/schedule-alerts.test.yaml` — covering a missed daily task, a
late one, a stuck every-minute task, a removed task and an overrun.

The **Telemetry / Scheduled Tasks** dashboard shows the same: seconds
since the scheduler last ran, tasks behind schedule, and runs in progress.

## Limits

- **Needs a shared store across the hosts that run the scheduler** —
  Redis, or one host. The gauges are written by `schedule:run` and read
  by Prometheus later; a process-local store loses them.
- **A task that has never run since monitoring was enabled** has no
  `last_seen` yet and is not evaluated until it has. A monthly task is
  watched from its first run on.
- **`when()` filters are not evaluated** when publishing due times (they
  can have side effects). A task whose filter says no fires
  `ScheduledTaskSkipped`, which counts as seen, so it is not reported as
  missed.
- Turn it off with `TELEMETRY_INSTRUMENT_SCHEDULE_MONITORING=false`.
