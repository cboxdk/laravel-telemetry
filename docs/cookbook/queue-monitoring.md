---
title: "Cookbook: Queue monitoring"
description: Depth, throughput, latency and in-flight gauges for queues
weight: 2
---

# Cookbook: Queue monitoring

Job spans, `queue.job.duration`, `queue.jobs.processed` and
`queue.jobs.failed` come free with `instrument.jobs`. This recipe adds the
rest of a production queue dashboard.

## Queue depth (pull — evaluated at scrape)

```php
Telemetry::contributes('queues', function (Registry $registry) {
    $registry->gauge('queue.depth', fn () => collect(['default', 'mail', 'exports'])
        ->map(fn (string $queue) => [(float) Queue::size($queue), ['queue' => $queue]])
        ->all(), unit: '{jobs}');
});
```

## Jobs in flight (push — adjusted at event time)

```php
Event::listen(JobProcessing::class, fn ($event) => Telemetry::gauge('queue.jobs.in_flight')
    ->increment(labels: ['queue' => $event->job->getQueue()]));

Event::listen(fn (JobProcessed|JobFailed $event) => Telemetry::gauge('queue.jobs.in_flight')
    ->decrement(labels: ['queue' => $event->job->getQueue()]));
```

## Oldest job age — the honest backlog signal

Depth lies when jobs are fast; age doesn't:

```php
$registry->gauge('queue.oldest_job.age', function () {
    $raw = Redis::connection()->lindex('queues:default', -1);
    $payload = $raw ? json_decode($raw, true) : null;

    return isset($payload['pushedAt']) ? microtime(true) - $payload['pushedAt'] : 0.0;
}, unit: 's');
```

(Or install `cboxdk/laravel-queue-metrics`, which publishes this and more
through its provider.)

## Trace a job back to its origin

Nothing to do — dispatched jobs carry the dispatcher's traceparent, so the
consumer span in Tempo hangs under the HTTP request that queued it:

```traceql
{ name =~ ".*ProcessOrder.*" && duration > 5s }
```

## Memory-leak tracking — no daemon required

Workers self-report their memory after every job:

```promql
# a climbing line per worker process IS the leak
worker_memory_rss_bytes{queue="default"}

# alert: any worker above 512 MB
max by (pid) (worker_memory_rss_bytes) > 536870912
```

For processes that never run app code between units of work (Reverb,
Horizon master), the optional monitor samples them by pgrep pattern:

```php
// config/telemetry.php
'monitor' => ['processes' => [
    'reverb' => 'reverb:start',
    'horizon' => 'horizon',
]],
```

```php
Schedule::command('telemetry:monitor --once')->everyMinute();  // cron mode
// or: php artisan telemetry:monitor --interval=15  (daemon under supervisor)
```

→ `process_memory_rss{process="reverb"}` and `process_count{process=...}`,
plus host CPU (proper between-tick delta), memory, load, disk and network.

## Alerts

```promql
# failure ratio above 5% for 10 minutes
sum(rate(queue_jobs_failed_total[5m]))
  / sum(rate(queue_jobs_processed_total[5m])) > 0.05

# backlog growing while workers are idle-ish
queue_depth{queue="default"} > 1000
  and rate(queue_jobs_processed_total{queue="default"}[5m]) < 1

# p95 job runtime regression
histogram_quantile(0.95, sum by (le, job_name)
  (rate(queue_job_duration_bucket[10m]))) > 30000
```
