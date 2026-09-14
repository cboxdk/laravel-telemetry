<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\SpanStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\InteractsWithQueue;

class TelemetryTestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void {}
}

it('runs jobs in a consumer span that is a child of the dispatch site', function () {
    config()->set('queue.default', 'sync');

    $collector = new CollectingExporter;
    Telemetry::addExporter($collector);

    $dispatchSpan = Telemetry::span('dispatching');

    TelemetryTestJob::dispatch();

    $dispatchSpan->end();

    Telemetry::flush();

    $jobSpans = [];

    foreach ($collector->batches() as $batch) {
        foreach ($batch->spans as $span) {
            if ($span->kind === SpanKind::Consumer) {
                $jobSpans[] = $span;
            }
        }
    }

    expect($jobSpans)->toHaveCount(1)
        ->and($jobSpans[0]->name)->toContain('TelemetryTestJob')
        ->and($jobSpans[0]->attributes()['messaging.system'])->toBe('laravel_queue')
        // Full W3C propagation: same trace, parented to the dispatch span.
        ->and($jobSpans[0]->traceId)->toBe($dispatchSpan->traceId)
        ->and($jobSpans[0]->parentSpanId)->toBe($dispatchSpan->spanId);
});

it('counts processed jobs', function () {
    config()->set('queue.default', 'sync');

    TelemetryTestJob::dispatch();

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());

    expect($families)->toHaveKey('queue.jobs.processed')
        ->and($families['queue.jobs.processed']->samples[0]->value)->toBe(1.0);
});

it('reports worker memory as a bounded distribution, not a series per pid', function () {
    // The pid was an unbounded label whose series were retired only on
    // WorkerStopping — which a worker killed by the OOM killer never
    // dispatches. So the gauge designed to catch a leaking worker leaked a
    // permanent series precisely when the worker died of the leak.
    app('queue');

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\Jobs\AnyJob');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('payload')->andReturn([]);

    $events = app('events');
    $events->dispatch(new JobProcessing('redis', $job));
    $events->dispatch(new JobProcessed('redis', $job));

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());

    expect($families)->toHaveKey('worker.memory.php')
        ->and($families['worker.memory.php']->samples[0]->labels)->toBe(['queue' => 'default'])
        ->and($families['worker.memory.php']->samples[0]->labels)->not->toHaveKey('pid');
});

/**
 * Laravel raises JobTimedOut and then SIGKILLs the worker — no shutdown
 * function, no terminating callback. Nothing else ever closes the attempt, so
 * the trace for a timed-out job (exactly the job you went looking for) was
 * absent, and the buffered counter died with the process reading a flat zero.
 */
it('closes out and ships a job killed by its timeout', function () {
    app('queue');

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\Jobs\SlowJob');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('payload')->andReturn([]);

    $collector = new CollectingExporter;
    Telemetry::addExporter($collector);

    $events = app('events');
    $events->dispatch(new JobProcessing('redis', $job));
    $events->dispatch(new JobTimedOut('redis', $job));

    $spans = collect($collector->batches())->flatMap(fn ($batch) => $batch->spans);

    // OTel messaging convention names the span "<destination> <operation>".
    expect($spans->pluck('name'))->toContain('App\Jobs\SlowJob process')
        ->and($spans->firstWhere('name', 'App\Jobs\SlowJob process')->status())->toBe(SpanStatus::Error);

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());

    expect($families)->toHaveKey('queue.jobs.timed_out')
        ->and($families['queue.jobs.timed_out']->samples[0]->value)->toBe(1.0);
});

/**
 * Laravel dispatches BOTH JobFailed and JobProcessed for a single attempt on
 * two ordinary paths: a job calling $this->fail($e) (Job::fail() dispatches
 * JobFailed, fire() then returns normally and the worker raises JobProcessed),
 * and a job arriving past --tries (markJobAsFailedIfAlreadyExceedsMaxAttempts
 * fails it, then `if ($job->isDeleted()) return $this->raiseAfterJobEvent()`).
 *
 * Counting both made every success-rate panel overstate success in proportion
 * to the failure rate — the worse the day, the better it looked.
 */
it('counts one attempt once, even when Laravel reports it failed and processed', function () {
    app('queue');

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\Jobs\SelfFailingJob');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('payload')->andReturn([]);

    $events = app('events');
    $events->dispatch(new JobProcessing('redis', $job));
    $events->dispatch(new JobFailed('redis', $job, new RuntimeException('nope')));
    $events->dispatch(new JobProcessed('redis', $job));

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());

    expect($families)->toHaveKey('queue.jobs.failed')
        ->and($families['queue.jobs.failed']->samples[0]->value)->toBe(1.0)
        ->and($families)->not->toHaveKey('queue.jobs.processed');
});
