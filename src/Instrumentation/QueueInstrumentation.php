<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

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
final class QueueInstrumentation
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
            $queue->createPayloadUsing(function () {
                $telemetry = $this->telemetry();
                $traceparent = $telemetry->traceparent();

                if ($traceparent === null) {
                    return [];
                }

                // Carry the full context: trace position, ambient custom
                // dimensions (team, plan, …) and where this dispatch came
                // from — the worker restores all of it.
                return ['telemetry' => array_filter([
                    'traceparent' => $traceparent,
                    'context' => $telemetry->contextAttributes() ?: null,
                    'origin' => $telemetry->tracer()->rootSpan()?->name,
                ])];
            });
        }

        if ($instrument) {
            $events->listen(JobProcessing::class, $this->jobProcessing(...));
            $events->listen(JobProcessed::class, $this->jobProcessed(...));
            $events->listen(JobReleasedAfterException::class, $this->jobReleased(...));
            $events->listen(JobFailed::class, $this->jobFailed(...));
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
                    $this->telemetry()->context($carried['context']);
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

            $span = $this->telemetry()->tracer()->startSpan(
                $event->job->resolveName().' process',
                SpanKind::Consumer,
                $attributes,
            );

            $this->jobSpans[] = $span;

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

                    $span->setAttributes([
                        'php.memory.peak_bytes' => $measured['memoryPeakBytes'],
                        'php.cpu.time_ms' => $measured['cpuTimeMs'],
                    ]);

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
            $this->telemetry()->flush();
            $this->telemetry()->resetContext();
        }
    }

    private function currentJobSpan(): ?Span
    {
        return $this->jobSpans === [] ? null : $this->jobSpans[array_key_last($this->jobSpans)];
    }
}
