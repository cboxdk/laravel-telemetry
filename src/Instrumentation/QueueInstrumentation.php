<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Contracts\ManagesRequestState;
use Cbox\Telemetry\Support\Cast;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\Support\ResourceUsage;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\SpanStatus;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Queue\QueueManager;

/**
 * Queue instrumentation.
 *
 * Dispatch side: every queued payload carries the full W3C traceparent
 * (trace id AND parent span id), so job spans are children of the dispatch
 * site — never detached roots.
 *
 * Worker side: each job runs in its own consumer span. All three outcomes
 * are covered — processed, released-for-retry (JobReleasedAfterException)
 * and terminally failed (JobFailed) — so retried attempts are never
 * invisible. Spans are kept on a stack so nested sync dispatches can't
 * clobber the outer job's span.
 */
final class QueueInstrumentation implements ManagesRequestState
{
    /** @var list<Span> */
    private array $jobSpans = [];

    /** @var array<int, ResourceUsage> keyed by span object id */
    private array $jobUsage = [];

    public function __construct(private readonly Container $container) {}

    /**
     * Resolved per event so Telemetry::fake() swaps take effect.
     */
    private function telemetry(): TelemetryManager
    {
        return $this->container->make(TelemetryManager::class);
    }

    public function register(QueueManager $queue, Dispatcher $events, bool $propagate, bool $instrument): void
    {
        if ($propagate) {
            $queue->createPayloadUsing(function (?string $connection = null, ?string $queueName = null, array $payload = []) {
                $telemetry = $this->telemetry();

                FailSafe::guard(fn () => $telemetry
                    ->counter('queue.jobs.dispatched', 'Jobs pushed onto the queue')
                    ->inc(1, [
                        'job.name' => is_string($payload['displayName'] ?? null) ? $payload['displayName'] : 'unknown',
                        'queue' => $queueName ?? 'default',
                    ]));

                // Carry the full context: trace position, ambient custom
                // dimensions (team, plan, …), the dispatch origin AND the
                // dispatch time — the worker restores/derives all of it.
                return ['telemetry' => array_filter([
                    'traceparent' => $telemetry->traceparent(),
                    'context' => $telemetry->contextAttributes() ?: null,
                    'origin' => $telemetry->tracer()->rootSpan()?->name,
                    'dispatched_at' => microtime(true),
                ])];
            });
        }

        if ($instrument) {
            $events->listen(JobProcessing::class, $this->jobProcessing(...));
            $events->listen(JobProcessed::class, $this->jobProcessed(...));
            $events->listen(JobReleasedAfterException::class, $this->jobReleased(...));
            $events->listen(JobFailed::class, $this->jobFailed(...));

            // Timeouts kill the attempt without a JobFailed on this path.
            if (class_exists(JobTimedOut::class)) {
                $events->listen(JobTimedOut::class, function ($event) {
                    FailSafe::guard(fn () => $this->telemetry()
                        ->counter('queue.jobs.timed_out', 'Jobs killed by their timeout')
                        ->inc(1, ['job.name' => $event->job->resolveName(), 'queue' => $event->job->getQueue() ?? 'default']));
                });
            }

            // `artisan queue:monitor` fires this above its size threshold.
            $events->listen(QueueBusy::class, function ($event) {
                FailSafe::guard(fn () => $this->telemetry()
                    ->gauge('queue.size', description: 'Queue depth reported by queue:monitor', unit: '{jobs}')
                    ->set((float) $event->size, ['connection' => $event->connection, 'queue' => $event->queue]));
            });
        }
    }

    private function jobProcessing(JobProcessing $event): void
    {
        FailSafe::guard(function () use ($event) {
            $payload = $event->job->payload();
            $carried = is_array($payload['telemetry'] ?? null) ? $payload['telemetry'] : [];

            // Sync jobs run inline inside the dispatcher's context — the
            // consumer span nests naturally and the caller's trace must
            // survive the job. Only real workers reset + continue.
            if ($event->connectionName !== 'sync') {
                $this->telemetry()->resetContext();

                if (is_string($carried['traceparent'] ?? null)) {
                    $this->telemetry()->continueTrace($carried['traceparent']);
                }

                // Restore the dispatcher's custom dimensions (team, plan…)
                // so the job's spans, events and logs carry them too.
                if (is_array($carried['context'] ?? null)) {
                    $this->telemetry()->context(Cast::scalarMap($carried['context']));
                }
            }

            $attributes = [
                'messaging.system' => 'laravel_queue',
                'messaging.operation.type' => 'process',
                'messaging.destination.name' => $event->job->getQueue(),
                'messaging.consumer.connection' => $event->connectionName,
                'laravel.job.class' => $event->job->resolveName(),
                'laravel.job.attempts' => $event->job->attempts(),
            ];

            // The human-readable dispatch origin ("POST /demo/orders",
            // "artisan reports:send") — queryable without walking the trace.
            if (is_string($carried['origin'] ?? null)) {
                $attributes['messaging.origin.name'] = $carried['origin'];
            } elseif ($event->connectionName === 'sync' && ($root = $this->telemetry()->tracer()->rootSpan()) !== null) {
                $attributes['messaging.origin.name'] = $root->name;
            }

            // Queue lag: how long the job waited from dispatch until this
            // attempt started.
            if (is_numeric($carried['dispatched_at'] ?? null) && $event->connectionName !== 'sync') {
                $waitMs = max(0.0, (microtime(true) - (float) $carried['dispatched_at']) * 1000);

                $attributes['messaging.wait_time_ms'] = round($waitMs, 2);

                $this->telemetry()
                    ->histogram('queue.job.wait_time', description: 'Time from dispatch until the attempt started', unit: 'ms')
                    ->record($waitMs, [
                        'job.name' => $event->job->resolveName(),
                        'queue' => $event->job->getQueue() ?? 'default',
                    ]);
            }

            $span = $this->telemetry()->tracer()->startSpan(
                $event->job->resolveName().' process',
                SpanKind::Consumer,
                $attributes,
            );

            $this->jobSpans[] = $span;

            $this->telemetry()->publishTraceContext();

            // Resource capture per job — skipped for sync jobs, which run
            // inside a request that is already being measured.
            if ($event->connectionName !== 'sync' && config('telemetry.instrument.resources', true)) {
                $this->jobUsage[spl_object_id($span)] = ResourceUsage::start();
            }
        });
    }

    private function jobProcessed(JobProcessed $event): void
    {
        $this->completeJob(
            job: $event->job->resolveName(),
            queue: $event->job->getQueue(),
            outcome: 'processed',
            sync: $event->connectionName === 'sync',
        );
    }

    /**
     * The job threw but will be retried — JobProcessed/JobFailed never
     * fire for this attempt, so it must be completed here or the span
     * leaks and the attempt goes unrecorded.
     */
    private function jobReleased(JobReleasedAfterException $event): void
    {
        FailSafe::guard(fn () => $this->currentJobSpan()?->setStatus(SpanStatus::Error, 'released for retry'));

        $this->completeJob(
            job: $event->job->resolveName(),
            queue: $event->job->getQueue(),
            outcome: 'released',
            sync: $event->connectionName === 'sync',
        );
    }

    private function jobFailed(JobFailed $event): void
    {
        FailSafe::guard(fn () => $this->currentJobSpan()?->recordException($event->exception));

        $this->completeJob(
            job: $event->job->resolveName(),
            queue: $event->job->getQueue(),
            outcome: 'failed',
            sync: $event->connectionName === 'sync',
        );
    }

    private function completeJob(string $job, ?string $queue, string $outcome, bool $sync): void
    {
        FailSafe::guard(function () use ($job, $queue, $outcome) {
            // "job.name", not "job" — a bare `job` label collides with
            // Prometheus' reserved scrape-job label and gets overwritten
            // by collectors.
            $labels = ['job.name' => $job, 'queue' => $queue ?? 'default'];

            if ($span = array_pop($this->jobSpans)) {
                if ($span->status() === SpanStatus::Unset) {
                    $span->setStatus($outcome === 'processed' ? SpanStatus::Ok : SpanStatus::Error);
                }

                $usage = $this->jobUsage[spl_object_id($span)] ?? null;
                unset($this->jobUsage[spl_object_id($span)]);

                if ($usage !== null) {
                    $measured = $usage->measure();

                    $span->setAttributes(array_filter([
                        'php.memory.peak_bytes' => $measured['memoryPeakBytes'],
                        'php.cpu.time_ms' => $measured['cpuTimeMs'],
                        'process.memory.rss_peak_bytes' => $measured['rssPeakBytes'],
                        'process.cpu.utilization' => $measured['cpuUtilization'],
                    ], static fn ($value) => $value !== null));

                    $this->telemetry()
                        ->histogram('queue.job.memory.peak', buckets: [4194304, 8388608, 16777216, 33554432, 67108864, 134217728, 268435456, 536870912, 1073741824], description: 'Peak memory per job', unit: 'By')
                        ->record((float) $measured['memoryPeakBytes'], $labels);

                    $this->telemetry()
                        ->histogram('queue.job.cpu.time', description: 'CPU time per job', unit: 'ms')
                        ->record($measured['cpuTimeMs'], $labels);
                }

                $span->end();

                $this->telemetry()
                    ->histogram('queue.job.duration', description: 'Queue job processing duration', unit: 'ms')
                    ->record($span->durationMs(), $labels);
            }

            $this->telemetry()
                ->counter("queue.jobs.{$outcome}", 'Queue job attempts by outcome')
                ->inc(1, $labels);
        });

        if (! $sync) {
            // Worker self-report: the process' CURRENT memory after each
            // job. A line that climbs job after job IS the memory leak —
            // no daemon required, the worker measures itself.
            FailSafe::guard(function () use ($queue) {
                $labels = ['queue' => $queue ?? 'default', 'pid' => (string) getmypid()];

                $this->telemetry()
                    ->gauge('worker.memory.php', description: 'Worker PHP allocator usage after each job', unit: 'By')
                    ->set((float) memory_get_usage(true), $labels);

                if (($rss = ResourceUsage::currentRssBytes()) !== null) {
                    $this->telemetry()
                        ->gauge('worker.memory.rss', description: 'Worker resident set size after each job', unit: 'By')
                        ->set((float) $rss, $labels);
                }
            });

            FailSafe::guard(function () {
                $this->telemetry()->flush();
                $this->telemetry()->resetContext();
            });
        }
    }

    private function currentJobSpan(): ?Span
    {
        return $this->jobSpans === [] ? null : $this->jobSpans[array_key_last($this->jobSpans)];
    }

    public function flushRequestState(): void
    {
        $this->jobSpans = [];
        $this->jobUsage = [];
    }
}
