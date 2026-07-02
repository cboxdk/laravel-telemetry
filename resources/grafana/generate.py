#!/usr/bin/env python3
"""Regenerates the bundled Grafana dashboards (dashboards/*.json).

Run after changing panels:  python3 resources/grafana/generate.py

The dashboards are generic: every query is scoped by the $service
variable (service_name label / resource.service.name), so one import
serves any number of apps shipping telemetry to the same stack.
Datasource UIDs default to the grafana/otel-lgtm convention
(prometheus/tempo/loki) and are import-time mappable in Grafana.
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
RF = f'{SVC},http_route=~"$route",http_request_method=~"$method",http_response_status_code=~"$status"'
QF = f'{SVC},queue=~"$queue",job_name=~"$job_name"'

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


def stat(title, expr, x, y, w=3, unit="short", thresholds=None):
    return {
        "id": nid(), "type": "stat", "title": title, "datasource": PROM,
        "gridPos": {"h": 4, "w": w, "x": x, "y": y},
        "targets": [target(expr)],
        "fieldConfig": {"defaults": {"unit": unit, "thresholds": thresholds or {
            "mode": "absolute", "steps": [{"color": "green", "value": None}]}}, "overrides": []},
        "options": {"reduceOptions": {"calcs": ["lastNotNull"]}, "colorMode": "value", "graphMode": "area"},
    }


def timeseries(title, targets, x, y, w=12, h=8, unit="short", stacked=False):
    return {
        "id": nid(), "type": "timeseries", "title": title, "datasource": PROM,
        "gridPos": {"h": h, "w": w, "x": x, "y": y}, "targets": targets,
        "fieldConfig": {"defaults": {"unit": unit, "custom": {
            "drawStyle": "line", "lineWidth": 2, "fillOpacity": 12,
            "stacking": {"mode": "normal" if stacked else "none"}}}, "overrides": []},
        "options": {"legend": {"displayMode": "table", "placement": "bottom", "calcs": ["mean", "max"]},
                    "tooltip": {"mode": "multi"}},
    }


def table(title, expr, x, y, w=12, h=8, unit="short", route_link=None):
    panel = {
        "id": nid(), "type": "table", "title": title, "datasource": PROM,
        "gridPos": {"h": h, "w": w, "x": x, "y": y},
        "targets": [target(expr, instant=True, fmt="table")],
        "transformations": [{"id": "organize", "options": {"excludeByName": {"Time": True}}}],
        "fieldConfig": {"defaults": {"unit": unit}, "overrides": []},
        "options": {"sortBy": [{"displayName": "Value", "desc": True}]},
    }
    if route_link:
        panel["fieldConfig"]["overrides"].append({
            "matcher": {"id": "byName", "options": "http_route"},
            "properties": [{"id": "links", "value": [{
                "title": "Drill down: requests on this route", "url": route_link}]}],
        })
    return panel


def traces(title, query, x, y, w=24, h=9, table_type="traces"):
    return {
        "id": nid(), "type": "table", "title": title, "datasource": TEMPO,
        "gridPos": {"h": h, "w": w, "x": x, "y": y},
        "targets": [{"refId": "A", "datasource": TEMPO, "queryType": "traceql",
                     "query": query, "limit": 20, "spss": 3, "tableType": table_type}],
        "fieldConfig": {"defaults": {}, "overrides": []}, "options": {},
    }


def logs(title, expr, x, y, w=24, h=9):
    return {
        "id": nid(), "type": "logs", "title": title, "datasource": LOKI,
        "gridPos": {"h": h, "w": w, "x": x, "y": y},
        "targets": [{"refId": "A", "datasource": LOKI, "expr": expr}],
        "options": {"showTime": True, "wrapLogMessage": True, "enableLogDetails": True,
                    "sortOrder": "Descending", "dedupStrategy": "none"},
        "fieldConfig": {"defaults": {}, "overrides": []},
    }


def qvar(name, metric, label):
    return {
        "name": name, "type": "query", "datasource": PROM,
        "query": {"query": f"label_values({metric}, {label})", "refId": name},
        "includeAll": True, "allValue": ".*", "multi": False, "refresh": 2,
        "current": {"text": "All", "value": "$__all"},
    }


def textvar(name, default, label=None):
    return {"name": name, "type": "textbox", "label": label or name,
            "query": default, "current": {"text": default, "value": default}, "options": []}


def dashboard(uid, title, panels, variables=None):
    service_var = qvar("service", f"{REQ}_count", "service_name")
    return {
        "uid": uid, "title": title, "tags": ["telemetry", "cboxdk"],
        "timezone": "browser", "schemaVersion": 39, "refresh": "30s",
        "time": {"from": "now-1h", "to": "now"},
        "templating": {"list": [service_var, *(variables or [])]},
        "links": [{"type": "dashboards", "tags": ["telemetry"], "title": "Telemetry",
                   "asDropdown": True, "includeVars": True, "keepTime": True}],
        "panels": panels, "editable": True,
    }


ROUTE_LINK = ("/d/cbox-tel-requests/telemetry-requests"
              "?${__url_time_range}&var-service=$service&var-route=${__data.fields.http_route}")

overview = dashboard("cbox-tel-overview", "Telemetry / Overview", [
    stat("Requests / min", f'sum(rate({REQ}_count{{{SVC}}}[5m])) * 60', 0, 0, unit="reqpm"),
    stat("p95 latency", f'histogram_quantile(0.95, sum by (le) (rate({REQ}_bucket{{{SVC}}}[5m])))', 3, 0, unit="ms"),
    stat("Error rate", f'100 * sum(rate({REQ}_count{{{SVC},http_response_status_code=~"5.."}}[5m])) / sum(rate({REQ}_count{{{SVC}}}[5m]))', 6, 0, unit="percent",
         thresholds={"mode": "absolute", "steps": [{"color": "green", "value": None}, {"color": "orange", "value": 1}, {"color": "red", "value": 5}]}),
    stat("p95 memory / req", f'histogram_quantile(0.95, sum by (le) (rate({MEM}_bucket{{{SVC}}}[10m])))', 9, 0, unit="bytes"),
    stat("Jobs ok / min", f'sum(rate(queue_jobs_processed_total{{{SVC}}}[5m])) * 60', 12, 0),
    stat("Jobs failed (5m)", f'sum(increase(queue_jobs_failed_total{{{SVC}}}[5m]))', 15, 0,
         thresholds={"mode": "absolute", "steps": [{"color": "green", "value": None}, {"color": "red", "value": 1}]}),
    stat("Tasks failed (1h)", f'sum(increase(schedule_tasks_failed_total{{{SVC}}}[1h]))', 18, 0,
         thresholds={"mode": "absolute", "steps": [{"color": "green", "value": None}, {"color": "red", "value": 1}]}),
    stat("Host memory", 'system_memory_utilization_ratio{state="used"} * 100', 21, 0, unit="percent"),
    timeseries("Requests by status", [target(f'sum by (http_response_status_code) (rate({REQ}_count{{{SVC}}}[$__rate_interval])) * 60', '{{http_response_status_code}}')], 0, 4, unit="reqpm", stacked=True),
    timeseries("Request latency", [
        target(f'histogram_quantile(0.50, sum by (le) (rate({REQ}_bucket{{{SVC}}}[$__rate_interval])))', 'p50'),
        target(f'histogram_quantile(0.95, sum by (le) (rate({REQ}_bucket{{{SVC}}}[$__rate_interval])))', 'p95'),
        target(f'histogram_quantile(0.99, sum by (le) (rate({REQ}_bucket{{{SVC}}}[$__rate_interval])))', 'p99'),
    ], 12, 4, unit="ms"),
    timeseries("Queue outcomes / min", [
        target(f'sum(rate(queue_jobs_processed_total{{{SVC}}}[$__rate_interval])) * 60', 'processed'),
        target(f'sum(rate(queue_jobs_released_total{{{SVC}}}[$__rate_interval])) * 60', 'released (retry)'),
        target(f'sum(rate(queue_jobs_failed_total{{{SVC}}}[$__rate_interval])) * 60', 'failed'),
    ], 0, 12, unit="opm"),
    timeseries("Scheduled tasks / hour", [
        target(f'sum(increase(schedule_tasks_processed_total{{{SVC}}}[1h]))', 'processed'),
        target(f'sum(increase(schedule_tasks_failed_total{{{SVC}}}[1h]))', 'failed'),
        target(f'sum(increase(schedule_tasks_skipped_total{{{SVC}}}[1h]))', 'skipped'),
    ], 12, 12),
    table("Routes by p95 latency (ms) — click to drill down", f'histogram_quantile(0.95, sum by (le, http_route) (rate({REQ}_bucket{{{SVC}}}[10m])))', 0, 20, unit="ms", route_link=ROUTE_LINK),
    table("Routes by p95 memory — click to drill down", f'histogram_quantile(0.95, sum by (le, http_route) (rate({MEM}_bucket{{{SVC}}}[10m])))', 12, 20, unit="bytes", route_link=ROUTE_LINK),
])

requests = dashboard("cbox-tel-requests", "Telemetry / Requests", [
    stat("Requests / min", f'sum(rate({REQ}_count{{{RF}}}[5m])) * 60', 0, 0, w=4, unit="reqpm"),
    stat("p50", f'histogram_quantile(0.50, sum by (le) (rate({REQ}_bucket{{{RF}}}[5m])))', 4, 0, w=4, unit="ms"),
    stat("p95", f'histogram_quantile(0.95, sum by (le) (rate({REQ}_bucket{{{RF}}}[5m])))', 8, 0, w=4, unit="ms"),
    stat("p99", f'histogram_quantile(0.99, sum by (le) (rate({REQ}_bucket{{{RF}}}[5m])))', 12, 0, w=4, unit="ms"),
    stat("p95 memory", f'histogram_quantile(0.95, sum by (le) (rate({MEM}_bucket{{{RF}}}[10m])))', 16, 0, w=4, unit="bytes"),
    stat("p95 CPU", f'histogram_quantile(0.95, sum by (le) (rate({CPU}_bucket{{{RF}}}[10m])))', 20, 0, w=4, unit="ms"),
    timeseries("Rate by route", [target(f'sum by (http_route) (rate({REQ}_count{{{RF}}}[$__rate_interval])) * 60', '{{http_route}}')], 0, 4, unit="reqpm"),
    timeseries("Latency percentiles (filtered)", [
        target(f'histogram_quantile(0.50, sum by (le) (rate({REQ}_bucket{{{RF}}}[$__rate_interval])))', 'p50'),
        target(f'histogram_quantile(0.95, sum by (le) (rate({REQ}_bucket{{{RF}}}[$__rate_interval])))', 'p95'),
    ], 12, 4, unit="ms"),
    timeseries("p95 memory by route", [target(f'histogram_quantile(0.95, sum by (le, http_route) (rate({MEM}_bucket{{{RF}}}[$__rate_interval])))', '{{http_route}}')], 0, 12, unit="bytes"),
    timeseries("p95 CPU time by route", [target(f'histogram_quantile(0.95, sum by (le, http_route) (rate({CPU}_bucket{{{RF}}}[$__rate_interval])))', '{{http_route}}')], 12, 12, unit="ms"),
    traces("Request traces — click for the waterfall (per-span CPU/memory in attributes)",
           f'{{{TSVC} && kind=server && name=~"$method $route" && duration>${{minms}}ms}}', 0, 20),
    traces("Failing requests", f'{{{TSVC} && kind=server && status=error}}', 0, 29),
    logs("Correlated logs", '{service_name=~"$service"}', 0, 38),
], variables=[
    qvar("route", f"{REQ}_count", "http_route"),
    qvar("method", f"{REQ}_count", "http_request_method"),
    qvar("status", f"{REQ}_count", "http_response_status_code"),
    textvar("minms", "0", "min duration (ms)"),
])

queue = dashboard("cbox-tel-queue", "Telemetry / Queue & Schedule", [
    stat("Processed / min", f'sum(rate(queue_jobs_processed_total{{{QF}}}[5m])) * 60', 0, 0, w=4),
    stat("Released / min", f'sum(rate(queue_jobs_released_total{{{QF}}}[5m])) * 60', 4, 0, w=4,
         thresholds={"mode": "absolute", "steps": [{"color": "green", "value": None}, {"color": "orange", "value": 0.1}]}),
    stat("Failed / min", f'sum(rate(queue_jobs_failed_total{{{QF}}}[5m])) * 60', 8, 0, w=4,
         thresholds={"mode": "absolute", "steps": [{"color": "green", "value": None}, {"color": "red", "value": 0.1}]}),
    stat("p95 job duration", f'histogram_quantile(0.95, sum by (le) (rate(queue_job_duration_milliseconds_bucket{{{QF}}}[5m])))', 12, 0, w=4, unit="ms"),
    stat("p95 job memory", f'histogram_quantile(0.95, sum by (le) (rate(queue_job_memory_peak_bytes_bucket{{{QF}}}[10m])))', 16, 0, w=4, unit="bytes"),
    stat("p95 job CPU", f'histogram_quantile(0.95, sum by (le) (rate(queue_job_cpu_time_milliseconds_bucket{{{QF}}}[10m])))', 20, 0, w=4, unit="ms"),
    timeseries("Outcomes by job", [
        target(f'sum by (job_name) (rate(queue_jobs_processed_total{{{QF}}}[$__rate_interval])) * 60', 'ok {{job_name}}'),
        target(f'sum by (job_name) (rate(queue_jobs_released_total{{{QF}}}[$__rate_interval])) * 60', 'retry {{job_name}}'),
        target(f'sum by (job_name) (rate(queue_jobs_failed_total{{{QF}}}[$__rate_interval])) * 60', 'fail {{job_name}}'),
    ], 0, 4, unit="opm"),
    timeseries("p95 duration by job", [target(f'histogram_quantile(0.95, sum by (le, job_name) (rate(queue_job_duration_milliseconds_bucket{{{QF}}}[$__rate_interval])))', '{{job_name}}')], 12, 4, unit="ms"),
    timeseries("p95 memory by job", [target(f'histogram_quantile(0.95, sum by (le, job_name) (rate(queue_job_memory_peak_bytes_bucket{{{QF}}}[$__rate_interval])))', '{{job_name}}')], 0, 12, unit="bytes"),
    timeseries("Scheduled task p95 duration", [target(f'histogram_quantile(0.95, sum by (le, task) (rate(schedule_task_duration_milliseconds_bucket{{{SVC}}}[$__rate_interval])))', '{{task}}')], 12, 12, unit="ms"),
    traces("Job runs (consumer spans) — origin request in messaging.origin.name",
           f'{{{TSVC} && kind=consumer}}', 0, 20, table_type="spans"),
    traces("Failed job attempts", f'{{{TSVC} && kind=consumer && status=error}}', 0, 29),
    logs("Worker logs", '{service_name=~"$service"} |~ "(?i)(job|queue|schedule)"', 0, 38),
], variables=[
    qvar("queue", "queue_jobs_processed_total", "queue"),
    qvar("job_name", "queue_jobs_processed_total", "job_name"),
])

drilldown = dashboard("cbox-tel-drilldown", "Telemetry / Drill-down", [
    traces("Requests for user $user (enduser.id)", f'{{{TSVC} && kind=server && span.enduser.id=~"$user"}}', 0, 0),
    traces("Memory hogs — requests over $minmb MB (php.memory.peak_bytes)",
           f'{{{TSVC} && kind=server && span.php.memory.peak_bytes > $minmb000000}}', 0, 9),
    traces("Slowest DB queries (> $minms ms) — span level",
           f'{{{TSVC} && name="db.query" && duration>${{minms}}ms}} | select(span.db.query.text, span.db.namespace)', 0, 18, table_type="spans"),
    traces("Outgoing calls (client spans)", f'{{{TSVC} && kind=client && name!="db.query"}}', 0, 27, table_type="spans"),
    traces("All error spans", f'{{{TSVC} && status=error}}', 0, 36),
    logs("Warnings & errors", '{service_name=~"$service"} | detected_level=~"(warn|error).*"', 0, 45),
], variables=[
    textvar("user", ".+", "user id"),
    textvar("minms", "10", "min duration (ms)"),
    textvar("minmb", "64", "min memory (MB)"),
])

os.makedirs(OUT, exist_ok=True)

for name, dash in [("overview", overview), ("requests", requests), ("queue", queue), ("drilldown", drilldown)]:
    path = os.path.join(OUT, f"telemetry-{name}.json")
    with open(path, "w") as f:
        json.dump(dash, f, indent=2)
    print(f"{path}: {len(dash['panels'])} panels")
