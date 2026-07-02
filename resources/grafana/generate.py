#!/usr/bin/env python3
"""Regenerates the bundled Grafana dashboard suite (dashboards/*.json).

Run after changing panels:  python3 resources/grafana/generate.py

Thirteen dashboards mirroring the Nightwatch sidebar, linked as top-bar
tabs (dashboard links, asDropdown=false). Every query is scoped by the
$service variable; datasource UIDs follow the grafana/otel-lgtm
convention (prometheus/tempo/loki) and are mappable at import time.

Visual language:
  green = healthy/2xx/hit · orange = warn/4xx/retry/miss · red = error/5xx/fail
  smooth gradient lines, soft-zero axes, shared crosshair, section rows.
"""

import json
import os

OUT = os.path.join(os.path.dirname(__file__), "dashboards")
PROM = {"type": "prometheus", "uid": "prometheus"}
TEMPO = {"type": "tempo", "uid": "tempo"}
LOKI = {"type": "loki", "uid": "loki"}

REQ = "http_server_request_duration_milliseconds"
MEM = "http_server_memory_peak_bytes"
CPU = "http_server_cpu_time_milliseconds"
SVC = 'service_name=~"$service"'
TSVC = 'resource.service.name=~"$service"'

_id = 0


def nid():
    global _id
    _id += 1
    return _id


def target(expr, legend="__auto", instant=False, fmt=None):
    t = {"refId": "Q" + str(nid()), "expr": expr, "legendFormat": legend, "datasource": PROM}
    if instant:
        t.update({"instant": True, "range": False})
    if fmt:
        t["format"] = fmt
    return t


def color_over(mapping, regex=False):
    """Fixed semantic colors per series (by legend name or regex)."""
    return [{
        "matcher": {"id": "byRegexp" if regex else "byName", "options": key},
        "properties": [{"id": "color", "value": {"mode": "fixed", "fixedColor": color}}],
    } for key, color in mapping.items()]


def ok_at(steps):
    return {"mode": "absolute", "steps": [{"color": "green", "value": None}, *steps]}


def warn_at(value, color="red"):
    return ok_at([{"color": color, "value": value}])


def stat(title, expr, x, y, w=4, unit="short", thresholds=None, bg=False, decimals=1, description=None, zero=False):
    if zero:
        expr = f"({expr}) or vector(0)"
    panel = {
        "id": nid(), "type": "stat", "title": title, "datasource": PROM,
        "gridPos": {"h": 4, "w": w, "x": x, "y": y},
        "targets": [target(expr)],
        "fieldConfig": {"defaults": {
            "unit": unit, "decimals": decimals,
            "thresholds": thresholds or {"mode": "absolute", "steps": [{"color": "text", "value": None}]},
        }, "overrides": []},
        "options": {
            "reduceOptions": {"calcs": ["lastNotNull"]},
            "colorMode": "background" if bg else "value",
            "graphMode": "area", "justifyMode": "auto", "textMode": "auto",
        },
    }
    if description:
        panel["description"] = description
    return panel


def timeseries(title, targets, x, y, w=12, h=8, unit="short", stacked=False,
               colors=None, regex_colors=None, legend="list", threshold_line=None,
               description=None):
    overrides = []
    if colors:
        overrides += color_over(colors)
    if regex_colors:
        overrides += color_over(regex_colors, regex=True)

    defaults = {
        "unit": unit, "min": 0,
        "custom": {
            "drawStyle": "line", "lineInterpolation": "smooth", "lineWidth": 2,
            "fillOpacity": 18, "gradientMode": "opacity", "showPoints": "never",
            "spanNulls": True, "axisSoftMin": 0,
            "stacking": {"mode": "normal" if stacked else "none"},
        },
    }

    if threshold_line is not None:
        defaults["custom"]["thresholdsStyle"] = {"mode": "dashed"}
        defaults["thresholds"] = warn_at(threshold_line)

    panel = {
        "id": nid(), "type": "timeseries", "title": title, "datasource": PROM,
        "gridPos": {"h": h, "w": w, "x": x, "y": y}, "targets": targets,
        "fieldConfig": {"defaults": defaults, "overrides": overrides},
        "options": {
            "legend": ({"displayMode": "table", "placement": "bottom", "calcs": ["mean", "max"]}
                       if legend == "table" else {"displayMode": "list", "placement": "bottom"}),
            "tooltip": {"mode": "multi", "sort": "desc"},
        },
    }
    if description:
        panel["description"] = description
    return panel


def table(title, expr, x, y, w=12, h=8, unit="short", field_link=None, gauge_max=None, description=None, decimals=1):
    overrides = []
    if gauge_max is not None:
        overrides.append({
            "matcher": {"id": "byName", "options": "Value"},
            "properties": [
                {"id": "custom.cellOptions", "value": {"type": "gauge", "mode": "gradient"}},
                {"id": "max", "value": gauge_max},
                {"id": "min", "value": 0},
                {"id": "thresholds", "value": {"mode": "percentage", "steps": [
                    {"color": "green", "value": None},
                    {"color": "orange", "value": 60},
                    {"color": "red", "value": 85},
                ]}},
            ],
        })
    if field_link:
        field, url, label = field_link
        overrides.append({
            "matcher": {"id": "byName", "options": field},
            "properties": [
                {"id": "links", "value": [{"title": label, "url": url}]},
                {"id": "custom.cellOptions", "value": {"type": "auto"}},
                {"id": "color", "value": {"mode": "fixed", "fixedColor": "light-blue"}},
            ],
        })

    panel = {
        "id": nid(), "type": "table", "title": title, "datasource": PROM,
        "gridPos": {"h": h, "w": w, "x": x, "y": y},
        "targets": [target(expr, instant=True, fmt="table")],
        "transformations": [{"id": "organize", "options": {"excludeByName": {"Time": True}}}],
        "fieldConfig": {"defaults": {"unit": unit, "decimals": decimals,
                                     "custom": {"align": "auto", "filterable": False}}, "overrides": overrides},
        "options": {"sortBy": [{"displayName": "Value", "desc": True}],
                    "cellHeight": "md", "footer": {"show": False}},
    }
    if description:
        panel["description"] = description
    return panel


def traces(title, query, x, y, w=24, h=9, table_type="traces", description=None):
    panel = {
        "id": nid(), "type": "table", "title": title, "datasource": TEMPO,
        "gridPos": {"h": h, "w": w, "x": x, "y": y},
        "targets": [{"refId": "A", "datasource": TEMPO, "queryType": "traceql",
                     "query": query, "limit": 20, "spss": 3, "tableType": table_type}],
        "fieldConfig": {"defaults": {"custom": {"filterable": False}}, "overrides": []},
        "options": {"cellHeight": "sm"},
    }
    if description:
        panel["description"] = description
    return panel


def logs(title, expr, x, y, w=24, h=9, description=None):
    panel = {
        "id": nid(), "type": "logs", "title": title, "datasource": LOKI,
        "gridPos": {"h": h, "w": w, "x": x, "y": y},
        "targets": [{"refId": "A", "datasource": LOKI, "expr": expr}],
        "options": {"showTime": True, "wrapLogMessage": True, "enableLogDetails": True,
                    "sortOrder": "Descending", "dedupStrategy": "none", "prettifyLogMessage": False},
        "fieldConfig": {"defaults": {}, "overrides": []},
    }
    if description:
        panel["description"] = description
    return panel


def row(title, y):
    return {"id": nid(), "type": "row", "title": title, "collapsed": False,
            "gridPos": {"h": 1, "w": 24, "x": 0, "y": y}, "panels": []}


def text(title, content, x, y, w=24, h=3):
    return {"id": nid(), "type": "text", "title": title,
            "gridPos": {"h": h, "w": w, "x": x, "y": y},
            "options": {"mode": "markdown", "content": content},
            "fieldConfig": {"defaults": {}, "overrides": []}}


def qvar(name, metric, label):
    return {"name": name, "type": "query", "datasource": PROM,
            "query": {"query": f"label_values({metric}, {label})", "refId": name},
            "includeAll": True, "allValue": ".*", "multi": False, "refresh": 2,
            "current": {"text": "All", "value": "$__all"}}


def textvar(name, default, label=None):
    return {"name": name, "type": "textbox", "label": label or name,
            "query": default, "current": {"text": default, "value": default}, "options": []}


def dashboard(uid, title, panels, variables=None):
    return {
        "uid": uid, "title": title, "tags": ["telemetry", "cboxdk"],
        "timezone": "browser", "schemaVersion": 39, "refresh": "30s",
        "graphTooltip": 1,  # shared crosshair across panels
        "time": {"from": "now-1h", "to": "now"},
        "templating": {"list": [qvar("service", f"{REQ}_count", "service_name"), *(variables or [])]},
        # The Nightwatch nav: every telemetry-tagged dashboard as a tab.
        "links": [{"type": "dashboards", "tags": ["telemetry"], "asDropdown": False,
                   "includeVars": True, "keepTime": True, "title": ""}],
        "panels": panels, "editable": True,
    }


STATUS_COLORS = {"2..": "green", "3..": "blue", "4..": "orange", "5..": "red"}
OUTCOME_COLORS = {"processed": "green", "released": "orange", "failed": "red",
                  "ok": "green", "retry": "orange", "fail": "red", "skipped": "yellow",
                  "hits": "green", "misses": "orange"}
PERCENTILE_COLORS = {"p50": "green", "p95": "orange", "p99": "red"}

LINK_REQ = "/d/cbox-tel-requests?${__url_time_range}&var-service=$service&var-route=${__data.fields.http_route}"
LINK_JOB = "/d/cbox-tel-jobs?${__url_time_range}&var-service=$service&var-job_name=${__data.fields.job_name}"

D = {}

# ── 1 · Overview ─────────────────────────────────────────────────────
D["overview"] = dashboard("cbox-tel-overview", "Telemetry", [
    row("Activity", 0),
    stat("Requests / min", f'sum(rate({REQ}_count{{{SVC}}}[5m])) * 60', 0, 1, w=3, unit="reqpm", decimals=0),
    stat("p95 latency", f'histogram_quantile(0.95, sum by (le) (rate({REQ}_bucket{{{SVC}}}[5m])))', 3, 1, w=3, unit="ms", thresholds=ok_at([{"color": "orange", "value": 500}, {"color": "red", "value": 2000}])),
    stat("Error rate", f'100 * sum(rate({REQ}_count{{{SVC},http_response_status_code=~"5.."}}[5m])) / sum(rate({REQ}_count{{{SVC}}}[5m]))', 6, 1, w=3, unit="percent", bg=True, thresholds=ok_at([{"color": "orange", "value": 1}, {"color": "red", "value": 5}])),
    stat("Exceptions / h", f'sum(increase(exceptions_reported_total{{{SVC}}}[1h]))', 9, 1, w=3, decimals=0, thresholds=warn_at(10, "orange"), zero=True),
    stat("Jobs ok / min", f'sum(rate(queue_jobs_processed_total{{{SVC}}}[5m])) * 60', 12, 1, w=3, decimals=0),
    stat("Jobs failed (5m)", f'sum(increase(queue_jobs_failed_total{{{SVC}}}[5m]))', 15, 1, w=3, bg=True, decimals=0, thresholds=warn_at(1), zero=True),
    stat("Tasks failed (1h)", f'sum(increase(schedule_tasks_failed_total{{{SVC}}}[1h]))', 18, 1, w=3, decimals=0, thresholds=warn_at(1), zero=True),
    stat("Host memory", 'system_memory_utilization_ratio{state="used"} * 100', 21, 1, w=3, unit="percent", decimals=0, thresholds=ok_at([{"color": "orange", "value": 80}, {"color": "red", "value": 92}])),
    timeseries("Requests by status", [target(f'sum by (http_response_status_code) (rate({REQ}_count{{{SVC}}}[$__rate_interval])) * 60', '{{http_response_status_code}}')], 0, 5, unit="reqpm", stacked=True, regex_colors=STATUS_COLORS),
    timeseries("Latency percentiles", [
        target(f'histogram_quantile(0.50, sum by (le) (rate({REQ}_bucket{{{SVC}}}[$__rate_interval])))', 'p50'),
        target(f'histogram_quantile(0.95, sum by (le) (rate({REQ}_bucket{{{SVC}}}[$__rate_interval])))', 'p95'),
        target(f'histogram_quantile(0.99, sum by (le) (rate({REQ}_bucket{{{SVC}}}[$__rate_interval])))', 'p99'),
    ], 12, 5, unit="ms", colors=PERCENTILE_COLORS, threshold_line=1000),
    row("Application", 13),
    timeseries("Queue outcomes / min", [
        target(f'sum(rate(queue_jobs_processed_total{{{SVC}}}[$__rate_interval])) * 60', 'processed'),
        target(f'sum(rate(queue_jobs_released_total{{{SVC}}}[$__rate_interval])) * 60', 'released'),
        target(f'sum(rate(queue_jobs_failed_total{{{SVC}}}[$__rate_interval])) * 60', 'failed'),
    ], 0, 14, w=8, unit="opm", colors=OUTCOME_COLORS),
    timeseries("Exceptions by class", [target(f'topk(5, sum by (exception) (increase(exceptions_reported_total{{{SVC}}}[$__rate_interval])))', '{{exception}}')], 8, 14, w=8),
    timeseries("Scheduled tasks / h", [
        target(f'sum(increase(schedule_tasks_processed_total{{{SVC}}}[1h]))', 'processed'),
        target(f'sum(increase(schedule_tasks_failed_total{{{SVC}}}[1h]))', 'failed'),
        target(f'sum(increase(schedule_tasks_skipped_total{{{SVC}}}[1h]))', 'skipped'),
    ], 16, 14, w=8, colors=OUTCOME_COLORS),
    row("Drill-down", 22),
    table("Routes by p95 — click a route to drill down", f'histogram_quantile(0.95, sum by (le, http_route) (rate({REQ}_bucket{{{SVC}}}[10m]))) > 0', 0, 23, unit="ms",
          field_link=("http_route", LINK_REQ, "Open in Requests"), gauge_max=2000),
    table("Jobs by p95 — click a job to drill down", f'histogram_quantile(0.95, sum by (le, job_name) (rate(queue_job_duration_milliseconds_bucket{{{SVC}}}[10m]))) > 0', 12, 23, unit="ms",
          field_link=("job_name", LINK_JOB, "Open in Jobs"), gauge_max=5000),
    traces("Latest failing traces — requests, jobs and tasks", f'{{{TSVC} && status=error}}', 0, 31),
])

# ── 2 · Requests ─────────────────────────────────────────────────────
RF = f'{SVC},http_route=~"$route",http_request_method=~"$method",http_response_status_code=~"$status"'
D["requests"] = dashboard("cbox-tel-requests", "Telemetry / Requests", [
    stat("Requests / min", f'sum(rate({REQ}_count{{{RF}}}[5m])) * 60', 0, 0, unit="reqpm", decimals=0),
    stat("p50", f'histogram_quantile(0.50, sum by (le) (rate({REQ}_bucket{{{RF}}}[5m])))', 4, 0, unit="ms"),
    stat("p95", f'histogram_quantile(0.95, sum by (le) (rate({REQ}_bucket{{{RF}}}[5m])))', 8, 0, unit="ms", thresholds=ok_at([{"color": "orange", "value": 500}, {"color": "red", "value": 2000}])),
    stat("p99", f'histogram_quantile(0.99, sum by (le) (rate({REQ}_bucket{{{RF}}}[5m])))', 12, 0, unit="ms"),
    stat("p95 memory", f'histogram_quantile(0.95, sum by (le) (rate({MEM}_bucket{{{RF}}}[10m])))', 16, 0, unit="bytes"),
    stat("p95 CPU", f'histogram_quantile(0.95, sum by (le) (rate({CPU}_bucket{{{RF}}}[10m])))', 20, 0, unit="ms"),
    row("Traffic & latency", 4),
    timeseries("Rate by route", [target(f'sum by (http_route) (rate({REQ}_count{{{RF}}}[$__rate_interval])) * 60', '{{http_route}}')], 0, 5, unit="reqpm"),
    timeseries("Latency percentiles", [
        target(f'histogram_quantile(0.50, sum by (le) (rate({REQ}_bucket{{{RF}}}[$__rate_interval])))', 'p50'),
        target(f'histogram_quantile(0.95, sum by (le) (rate({REQ}_bucket{{{RF}}}[$__rate_interval])))', 'p95'),
    ], 12, 5, unit="ms", colors=PERCENTILE_COLORS, threshold_line=1000),
    row("Resources per route", 13),
    timeseries("p95 memory by route", [target(f'histogram_quantile(0.95, sum by (le, http_route) (rate({MEM}_bucket{{{RF}}}[$__rate_interval])))', '{{http_route}}')], 0, 14, unit="bytes"),
    timeseries("p95 CPU by route", [target(f'histogram_quantile(0.95, sum by (le, http_route) (rate({CPU}_bucket{{{RF}}}[$__rate_interval])))', '{{http_route}}')], 12, 14, unit="ms"),
    row("Traces & logs", 22),
    traces("Request traces — click for the waterfall (per-span CPU/memory in attributes)",
           f'{{{TSVC} && kind=server && name=~"$method $route" && duration>${{minms}}ms}}', 0, 23),
    traces("Failing requests", f'{{{TSVC} && kind=server && status=error}}', 0, 32),
    logs("Correlated logs — trace_id links to Tempo", '{service_name=~"$service"}', 0, 41),
], variables=[qvar("route", f"{REQ}_count", "http_route"), qvar("method", f"{REQ}_count", "http_request_method"),
              qvar("status", f"{REQ}_count", "http_response_status_code"), textvar("minms", "0", "min duration (ms)")])

# ── 3 · Jobs ─────────────────────────────────────────────────────────
QF = f'{SVC},queue=~"$queue",job_name=~"$job_name"'
D["jobs"] = dashboard("cbox-tel-jobs", "Telemetry / Jobs", [
    stat("Dispatched / min", f'sum(rate(queue_jobs_dispatched_total{{{QF}}}[5m])) * 60', 0, 0, decimals=0),
    stat("Processed / min", f'sum(rate(queue_jobs_processed_total{{{QF}}}[5m])) * 60', 4, 0, decimals=0),
    stat("Released / min", f'sum(rate(queue_jobs_released_total{{{QF}}}[5m])) * 60', 8, 0, thresholds=warn_at(0.1, "orange")),
    stat("Failed / min", f'sum(rate(queue_jobs_failed_total{{{QF}}}[5m])) * 60', 12, 0, bg=True, thresholds=warn_at(0.1)),
    stat("p95 duration", f'histogram_quantile(0.95, sum by (le) (rate(queue_job_duration_milliseconds_bucket{{{QF}}}[5m])))', 16, 0, unit="ms"),
    stat("p95 wait time", f'histogram_quantile(0.95, sum by (le) (rate(queue_job_wait_time_milliseconds_bucket{{{QF}}}[5m])))', 20, 0, unit="ms", thresholds=warn_at(30000, "orange"),
         description="Time from dispatch until the attempt started — queue lag."),
    row("Throughput & latency", 4),
    timeseries("Outcomes by job", [
        target(f'sum by (job_name) (rate(queue_jobs_processed_total{{{QF}}}[$__rate_interval])) * 60', 'ok {{job_name}}'),
        target(f'sum by (job_name) (rate(queue_jobs_released_total{{{QF}}}[$__rate_interval])) * 60', 'retry {{job_name}}'),
        target(f'sum by (job_name) (rate(queue_jobs_failed_total{{{QF}}}[$__rate_interval])) * 60', 'fail {{job_name}}'),
    ], 0, 5, unit="opm", regex_colors={"^ok .*": "green", "^retry .*": "orange", "^fail .*": "red"}),
    timeseries("Queue wait time p95 (dispatch → start)", [target(f'histogram_quantile(0.95, sum by (le, queue) (rate(queue_job_wait_time_milliseconds_bucket{{{QF}}}[$__rate_interval])))', '{{queue}}')], 12, 5, unit="ms", threshold_line=30000),
    row("Resources & leaks", 13),
    timeseries("p95 duration by job", [target(f'histogram_quantile(0.95, sum by (le, job_name) (rate(queue_job_duration_milliseconds_bucket{{{QF}}}[$__rate_interval])))', '{{job_name}}')], 0, 14, unit="ms"),
    timeseries("Worker memory — a climbing line IS the leak", [
        target(f'worker_memory_rss_bytes{{{SVC}}}', 'rss pid {{pid}}'),
        target(f'worker_memory_php_bytes{{{SVC}}}', 'php pid {{pid}}'),
    ], 12, 14, unit="bytes", description="Workers self-report after every job. One line per worker process."),
    row("Traces", 22),
    traces("Job runs — origin request in messaging.origin.name, queue lag in wait_time_ms",
           f'{{{TSVC} && kind=consumer}} | select(span.messaging.origin.name, span.messaging.wait_time_ms)', 0, 23, table_type="spans"),
    traces("Failed attempts", f'{{{TSVC} && kind=consumer && status=error}}', 0, 32),
], variables=[qvar("queue", "queue_jobs_processed_total", "queue"), qvar("job_name", "queue_jobs_processed_total", "job_name")])

# ── 4 · Commands ─────────────────────────────────────────────────────
CF = f'{SVC},command=~"$command"'
D["commands"] = dashboard("cbox-tel-commands", "Telemetry / Commands", [
    stat("Runs / h", f'sum(increase(commands_completed_total{{{CF}}}[1h])) + sum(increase(commands_failed_total{{{CF}}}[1h]))', 0, 0, w=6, decimals=0),
    stat("Failed / h", f'sum(increase(commands_failed_total{{{CF}}}[1h]))', 6, 0, w=6, bg=True, decimals=0, thresholds=warn_at(1), zero=True),
    stat("avg duration", f'sum(rate(command_duration_milliseconds_sum{{{CF}}}[1h])) / sum(rate(command_duration_milliseconds_count{{{CF}}}[1h]))', 12, 0, w=6, unit="ms"),
    stat("p95 duration", f'histogram_quantile(0.95, sum by (le) (rate(command_duration_milliseconds_bucket{{{CF}}}[1h])))', 18, 0, w=6, unit="ms"),
    table("Runs by command (1h)", f'sum by (command) (increase(commands_completed_total{{{CF}}}[1h]))', 0, 4, decimals=0),
    table("p95 by command", f'histogram_quantile(0.95, sum by (le, command) (rate(command_duration_milliseconds_bucket{{{CF}}}[1h]))) > 0', 12, 4, unit="ms", gauge_max=60000),
    traces("Command traces", f'{{{TSVC} && name=~"artisan .*"}}', 0, 12),
    text("Enable", "Command spans/metrics are opt-in: `TELEMETRY_INSTRUMENT_COMMANDS=true`.", 0, 21),
], variables=[qvar("command", "commands_completed_total", "command")])

# ── 5 · Scheduled Tasks ──────────────────────────────────────────────
SF = f'{SVC},task=~"$task"'
D["schedule"] = dashboard("cbox-tel-schedule", "Telemetry / Scheduled Tasks", [
    stat("Processed / h", f'sum(increase(schedule_tasks_processed_total{{{SF}}}[1h]))', 0, 0, w=6, decimals=0),
    stat("Failed / h", f'sum(increase(schedule_tasks_failed_total{{{SF}}}[1h]))', 6, 0, w=6, bg=True, decimals=0, thresholds=warn_at(1), zero=True),
    stat("Skipped / h", f'sum(increase(schedule_tasks_skipped_total{{{SF}}}[1h]))', 12, 0, w=6, decimals=0, thresholds=warn_at(5, "orange"), zero=True,
         description="Skips from filters or withoutOverlapping locks — the outcome most monitoring misses."),
    stat("p95 duration", f'histogram_quantile(0.95, sum by (le) (rate(schedule_task_duration_milliseconds_bucket{{{SF}}}[1h])))', 18, 0, w=6, unit="ms"),
    timeseries("Outcomes by task", [
        target(f'sum by (task) (increase(schedule_tasks_processed_total{{{SF}}}[$__rate_interval]))', 'ok {{task}}'),
        target(f'sum by (task) (increase(schedule_tasks_failed_total{{{SF}}}[$__rate_interval]))', 'fail {{task}}'),
        target(f'sum by (task) (increase(schedule_tasks_skipped_total{{{SF}}}[$__rate_interval]))', 'skip {{task}}'),
    ], 0, 4, regex_colors={"^ok .*": "green", "^fail .*": "red", "^skip .*": "yellow"}),
    timeseries("p95 duration by task", [target(f'histogram_quantile(0.95, sum by (le, task) (rate(schedule_task_duration_milliseconds_bucket{{{SF}}}[$__rate_interval])))', '{{task}}')], 12, 4, unit="ms"),
    traces("Task runs", f'{{{TSVC} && name=~"schedule .*"}}', 0, 12),
    traces("Failed task runs", f'{{{TSVC} && name=~"schedule .*" && status=error}}', 0, 21),
], variables=[qvar("task", "schedule_tasks_processed_total", "task")])

# ── 6 · Exceptions ───────────────────────────────────────────────────
D["exceptions"] = dashboard("cbox-tel-exceptions", "Telemetry / Exceptions", [
    stat("Reported / h", f'sum(increase(exceptions_reported_total{{{SVC}}}[1h]))', 0, 0, w=8, bg=True, decimals=0, thresholds=warn_at(10, "orange"), zero=True,
         description="Everything passed to report() — handled exceptions included."),
    stat("5xx responses / h", f'sum(increase({REQ}_count{{{SVC},http_response_status_code=~"5.."}}[1h]))', 8, 0, w=8, decimals=0, thresholds=warn_at(1), zero=True),
    stat("Failed jobs+tasks / h", f'(sum(increase(queue_jobs_failed_total{{{SVC}}}[1h])) or vector(0)) + (sum(increase(schedule_tasks_failed_total{{{SVC}}}[1h])) or vector(0))', 16, 0, w=8, decimals=0, thresholds=warn_at(1)),
    timeseries("Exceptions by class", [target(f'sum by (exception) (increase(exceptions_reported_total{{{SVC}}}[$__rate_interval]))', '{{exception}}')], 0, 4, stacked=True),
    table("Total by class (1h)", f'sum by (exception) (increase(exceptions_reported_total{{{SVC}}}[1h]))', 12, 4, decimals=0),
    traces("Failing requests — full detail retained even in tail mode", f'{{{TSVC} && kind=server && status=error}}', 0, 12),
    traces("Failing jobs & tasks", f'{{{TSVC} && kind!=server && status=error}}', 0, 21),
    logs("Error logs", '{service_name=~"$service"} | detected_level=~"(error|fatal).*"', 0, 30),
])

# ── 7 · Queries ──────────────────────────────────────────────────────
D["queries"] = dashboard("cbox-tel-queries", "Telemetry / Queries", [
    traces("Slowest queries (> $minms ms) — SQL text on the span",
           f'{{{TSVC} && name="db.query" && duration>${{minms}}ms}} | select(span.db.query.text, span.db.namespace)', 0, 0, table_type="spans"),
    traces("N+1 suspects — requests running ≥ $minqueries queries",
           f'{{{TSVC} && kind=server && span.db.query.count >= $minqueries}} | select(span.db.query.count, span.db.query.time_ms)', 0, 9,
           description="db.query.count/time_ms tallies live on every root span."),
    traces("Query-heaviest jobs", f'{{{TSVC} && kind=consumer && span.db.query.count >= $minqueries}} | select(span.db.query.count, span.db.query.time_ms)', 0, 18),
    text("Tip", "Every root span carries `db.query.count` / `db.query.time_ms` tallies — even when individual query spans are filtered by `queries_min_duration` or tail mode.", 0, 27),
], variables=[textvar("minms", "10", "min query ms"), textvar("minqueries", "25", "min queries/request")])

# ── 8 · Cache ────────────────────────────────────────────────────────
D["cache"] = dashboard("cbox-tel-cache", "Telemetry / Cache", [
    stat("Hit ratio", f'100 * sum(rate(cache_operations_total{{{SVC},operation="hit"}}[10m])) / sum(rate(cache_operations_total{{{SVC},operation=~"hit|miss"}}[10m]))', 0, 0, w=8, unit="percent", bg=True, decimals=0,
         thresholds={"mode": "absolute", "steps": [{"color": "red", "value": None}, {"color": "orange", "value": 50}, {"color": "green", "value": 90}]}),
    stat("Ops / min", f'sum(rate(cache_operations_total{{{SVC}}}[5m])) * 60', 8, 0, w=8, unit="opm", decimals=0),
    stat("Writes / min", f'sum(rate(cache_operations_total{{{SVC},operation="write"}}[5m])) * 60', 16, 0, w=8, unit="opm", decimals=0),
    timeseries("Hits vs misses", [
        target(f'sum(rate(cache_operations_total{{{SVC},operation="hit"}}[$__rate_interval])) * 60', 'hits'),
        target(f'sum(rate(cache_operations_total{{{SVC},operation="miss"}}[$__rate_interval])) * 60', 'misses'),
    ], 0, 4, unit="opm", colors=OUTCOME_COLORS),
    timeseries("Operations by store", [target(f'sum by (store, operation) (rate(cache_operations_total{{{SVC}}}[$__rate_interval])) * 60', '{{store}} {{operation}}')], 12, 4, unit="opm", stacked=True),
    traces("Slowest cache operations — key on the span", f'{{{TSVC} && name=~"cache\\\\..*" && duration>1ms}} | select(span.cache.key, span.cache.store)', 0, 12, table_type="spans"),
    text("Enable", "Counters: `TELEMETRY_INSTRUMENT_CACHE=true` · Timeline spans with keys: `TELEMETRY_INSTRUMENT_CACHE_SPANS=true` (pair with `TELEMETRY_TRACE_DETAILS=tail` in high-traffic apps).", 0, 21),
])

# ── 9 · Outgoing Requests ───────────────────────────────────────────
D["outgoing"] = dashboard("cbox-tel-outgoing", "Telemetry / Outgoing Requests", [
    stat("Requests / min", f'sum(rate(http_client_request_duration_milliseconds_count{{{SVC}}}[5m])) * 60', 0, 0, w=6, unit="reqpm", decimals=0),
    stat("p95 latency", f'histogram_quantile(0.95, sum by (le) (rate(http_client_request_duration_milliseconds_bucket{{{SVC}}}[5m])))', 6, 0, w=6, unit="ms", thresholds=warn_at(2000, "orange")),
    stat("4xx+5xx / min", f'sum(rate(http_client_request_duration_milliseconds_count{{{SVC},http_response_status_code=~"[45].."}}[5m])) * 60', 12, 0, w=6, thresholds=warn_at(1, "orange")),
    stat("Connection failures / h", f'sum(increase(http_client_connection_failures_total{{{SVC}}}[1h]))', 18, 0, w=6, bg=True, decimals=0, thresholds=warn_at(1), zero=True),
    timeseries("p95 by host", [target(f'histogram_quantile(0.95, sum by (le, server_address) (rate(http_client_request_duration_milliseconds_bucket{{{SVC}}}[$__rate_interval])))', '{{server_address}}')], 0, 4, unit="ms"),
    timeseries("Rate by host & status", [target(f'sum by (server_address, http_response_status_code) (rate(http_client_request_duration_milliseconds_count{{{SVC}}}[$__rate_interval])) * 60', '{{server_address}} {{http_response_status_code}}')], 12, 4, unit="reqpm"),
    traces("Outgoing call spans", f'{{{TSVC} && kind=client && span.server.address != ""}} | select(span.server.address, span.url.path, span.http.response.status_code)', 0, 12, table_type="spans"),
])

# ── 10 · Mail & Notifications ───────────────────────────────────────
D["mail"] = dashboard("cbox-tel-mail", "Telemetry / Mail & Notifications", [
    stat("Mail sent / h", f'sum(increase(mail_sent_total{{{SVC}}}[1h]))', 0, 0, w=12, decimals=0),
    stat("Notifications / h", f'sum(increase(notifications_sent_total{{{SVC}}}[1h]))', 12, 0, w=12, decimals=0),
    timeseries("Notifications by channel", [target(f'sum by (channel) (increase(notifications_sent_total{{{SVC}}}[$__rate_interval]))', '{{channel}}')], 0, 4, stacked=True),
    table("By notification class (1h)", f'sum by (notification) (increase(notifications_sent_total{{{SVC}}}[1h]))', 12, 4, decimals=0),
    traces("Send spans (mail + notifications)", f'{{{TSVC} && (name="mail.send" || name="notification.send")}}', 0, 12, table_type="spans"),
])

# ── 11 · System ──────────────────────────────────────────────────────
D["system"] = dashboard("cbox-tel-system", "Telemetry / System", [
    stat("CPU", f'system_cpu_utilization_ratio{{{SVC}}} * 100', 0, 0, w=6, unit="percent", decimals=0, thresholds=ok_at([{"color": "orange", "value": 70}, {"color": "red", "value": 90}])),
    stat("Memory", 'system_memory_utilization_ratio{state="used"} * 100', 6, 0, w=6, unit="percent", decimals=0, thresholds=ok_at([{"color": "orange", "value": 80}, {"color": "red", "value": 92}])),
    stat("Load 1m", 'system_cpu_load_average_ratio{period="1m"}', 12, 0, w=6),
    stat("Disk used", 'system_filesystem_usage_bytes{state="used"} / (system_filesystem_usage_bytes{state="used"} + system_filesystem_usage_bytes{state="free"}) * 100', 18, 0, w=6, unit="percent", decimals=0, thresholds=ok_at([{"color": "orange", "value": 80}, {"color": "red", "value": 92}])),
    row("Host", 4),
    timeseries("Memory by state", [target('system_memory_usage_bytes', '{{state}}')], 0, 5, unit="bytes", stacked=True, colors={"used": "orange", "free": "green", "cached": "blue"}),
    timeseries("Load averages", [target('system_cpu_load_average_ratio', '{{period}}')], 12, 5),
    timeseries("Network I/O rate", [target('rate(system_network_io_bytes[$__rate_interval])', '{{direction}}')], 0, 13, unit="Bps"),
    timeseries("Monitored process groups", [target('process_memory_rss_bytes', '{{process}}')], 12, 13, unit="bytes",
                description="telemetry:monitor samples Reverb/Horizon/workers by pgrep pattern."),
    row("Workers", 21),
    timeseries("Worker leak curves — one line per worker process", [target(f'worker_memory_rss_bytes{{{SVC}}}', 'pid {{pid}}')], 0, 22, w=24, unit="bytes"),
])

# ── 12 · Users ───────────────────────────────────────────────────────
D["users"] = dashboard("cbox-tel-users", "Telemetry / Users", [
    traces("Requests for user $user", f'{{{TSVC} && kind=server && span.enduser.id=~"$user"}}', 0, 0),
    traces("Errors hit by user $user", f'{{{TSVC} && span.enduser.id=~"$user" && status=error}}', 0, 9),
    traces("Memory hogs (> $minmb MB)", f'{{{TSVC} && kind=server && span.php.memory.peak_bytes > $minmb000000}}', 0, 18),
    text("Tip", "Request spans carry `enduser.id` (opt-out: `TELEMETRY_INSTRUMENT_USER=false`). Enrich with names via `Telemetry::resolveUserUsing()` — explicit PII opt-in.", 0, 27),
], variables=[textvar("user", ".+", "user id"), textvar("minmb", "64", "min memory (MB)")])

# ── 13 · Logs ────────────────────────────────────────────────────────
D["logs"] = dashboard("cbox-tel-logs", "Telemetry / Logs", [
    logs("All logs — trace_id links to Tempo", '{service_name=~"$service"} |~ "$search"', 0, 0, h=12),
    logs("Warnings & errors", '{service_name=~"$service"} | detected_level=~"(warn|error|fatal).*"', 0, 12, h=10),
], variables=[textvar("search", "", "search")])


os.makedirs(OUT, exist_ok=True)

for stale in os.listdir(OUT):
    os.remove(os.path.join(OUT, stale))

for name, dash in D.items():
    path = os.path.join(OUT, f"telemetry-{name}.json")
    with open(path, "w") as f:
        json.dump(dash, f, indent=2)
    print(f"{name}: {len(dash['panels'])} panels")
