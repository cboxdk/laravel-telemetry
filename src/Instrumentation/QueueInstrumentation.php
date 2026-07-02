<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\SpanStatus;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\QueueManager;

/**
 * Queue instrumentation.
 *
 * Dispatch side: every queued payload carries the full W3C traceparent
 * (trace id AND parent span id), so job spans are children of the dispatch
 * site — never detached roots.
 *
 * Worker side: each job runs in its own consumer span, metrics are
 * recorded, and the buffer is flushed after every job.
 */
final class QueueInstrumentation
{
    private ?Span $jobSpan = null;

    public function __construct(private readonly TelemetryManager $telemetry) {}

    public function register(QueueManager $queue, Dispatcher $events, bool $propagate, bool $instrument): void
    {
        if ($propagate) {
            $queue->createPayloadUsing(function () {
                $traceparent = $this->telemetry->traceparent();

                return $traceparent === null ? [] : ['telemetry' => ['traceparent' => $traceparent]];
            });
        }

        if ($instrument) {
            $events->listen(JobProcessing::class, $this->jobProcessing(...));
            $events->listen(JobProcessed::class, $this->jobProcessed(...));
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
                $this->telemetry->resetContext();

                $payload = $event->job->payload();
                $traceparent = $payload['telemetry']['traceparent'] ?? null;

                if (is_string($traceparent)) {
                    $this->telemetry->continueTrace($traceparent);
                }
            }

            $this->jobSpan = $this->telemetry->tracer()->startSpan(
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
        $this->completeJob($event->job->resolveName(), $event->job->getQueue(), failed: false, sync: $event->connectionName === 'sync');
    }

    private function jobFailed(JobFailed $event): void
    {
        FailSafe::guard(fn () => $this->jobSpan?->recordException($event->exception));

        $this->completeJob($event->job->resolveName(), $event->job->getQueue(), failed: true, sync: $event->connectionName === 'sync');
    }

    private function completeJob(string $job, ?string $queue, bool $failed, bool $sync = false): void
    {
        FailSafe::guard(function () use ($job, $queue, $failed) {
            $labels = ['job' => $job, 'queue' => $queue ?? 'default'];

            if ($span = $this->jobSpan) {
                if ($span->status() === SpanStatus::Unset) {
                    $span->setStatus($failed ? SpanStatus::Error : SpanStatus::Ok);
                }

                $span->end();

                $this->telemetry
                    ->histogram('queue.job.duration', description: 'Queue job processing duration', unit: 'ms')
                    ->record($span->durationMs(), $labels);

                $this->jobSpan = null;
            }

            $this->telemetry
                ->counter($failed ? 'queue.jobs.failed' : 'queue.jobs.processed', 'Processed queue jobs')
                ->inc(1, $labels);
        });

        if (! $sync) {
            $this->telemetry->flush();
            $this->telemetry->resetContext();
        }
    }
}
