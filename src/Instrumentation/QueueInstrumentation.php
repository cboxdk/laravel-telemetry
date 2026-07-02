<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
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
                $traceparent = $this->telemetry()->traceparent();

                return $traceparent === null ? [] : ['telemetry' => ['traceparent' => $traceparent]];
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
            // Sync jobs run inline inside the dispatcher's context — the
            // consumer span nests naturally and the caller's trace must
            // survive the job. Only real workers reset + continue.
            if ($event->connectionName !== 'sync') {
                $this->telemetry()->resetContext();

                $payload = $event->job->payload();
                $traceparent = $payload['telemetry']['traceparent'] ?? null;

                if (is_string($traceparent)) {
                    $this->telemetry()->continueTrace($traceparent);
                }
            }

            $this->jobSpans[] = $this->telemetry()->tracer()->startSpan(
                $event->job->resolveName().' process',
                SpanKind::Consumer,
                [
                    'messaging.system' => 'laravel_queue',
                    'messaging.operation.type' => 'process',
                    'messaging.destination.name' => $event->job->getQueue(),
                    'messaging.consumer.connection' => $event->connectionName,
                    'laravel.job.class' => $event->job->resolveName(),
                    'laravel.job.attempts' => $event->job->attempts(),
                ],
            );
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
            $labels = ['job' => $job, 'queue' => $queue ?? 'default'];

            if ($span = array_pop($this->jobSpans)) {
                if ($span->status() === SpanStatus::Unset) {
                    $span->setStatus($outcome === 'processed' ? SpanStatus::Ok : SpanStatus::Error);
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
