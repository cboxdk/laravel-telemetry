<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\SpanStatus;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Laravel has an attempt path that announces no outcome at all.
 *
 * `Worker::handleJobException` dispatches JobReleasedAfterException only for
 * a job IT released — `! $job->isDeleted() && ! $job->isReleased() && !
 * $job->hasFailed()`. A job that calls `$this->release()` itself and then
 * throws, with retries left, therefore produces JobExceptionOccurred and
 * JobAttempted and none of the four events this instrumentation closes an
 * attempt on.
 *
 * The span used to stay on the stack and in the instrumentation's map for
 * the life of the worker: one leaked span per such attempt, later spans
 * mis-parented to it, and the shutdown path ending it as an error that
 * lasted until the process died.
 */
function abandonedTestJob(): Job
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\Jobs\SelfReleasingJob');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('payload')->andReturn([]);

    return $job;
}

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);

    app('queue');
});

it('leaves no span open for an attempt that reported no outcome', function () {
    $events = app('events');
    $job = abandonedTestJob();

    $events->dispatch(new JobProcessing('redis', $job));

    expect(Telemetry::tracer()->currentSpan())->not->toBeNull();

    $events->dispatch(new JobAttempted('redis', $job));

    // Nothing open on the tracer's context stack: the next job's span would
    // otherwise be parented to a job that finished long ago.
    expect(Telemetry::tracer()->currentSpan())->toBeNull();
});

it('moves no outcome counter for an attempt that reported no outcome', function () {
    $events = app('events');
    $job = abandonedTestJob();

    $events->dispatch(new JobProcessing('redis', $job));
    $events->dispatch(new JobAttempted('redis', $job));

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());

    expect($families->keys()->filter(fn (string $name) => str_starts_with($name, 'queue.jobs.')))->toBeEmpty();
});

/**
 * The attempt is still real work that threw, so it is exported rather than
 * discarded — it is the trace someone goes looking for.
 */
it('still exports the span, marked as having no outcome', function () {
    $events = app('events');
    $job = abandonedTestJob();

    $events->dispatch(new JobProcessing('redis', $job));
    $events->dispatch(new JobAttempted('redis', $job));

    Telemetry::flush();

    $spans = collect($this->collector->batches())
        ->flatMap(fn ($batch) => $batch->spans)
        ->filter(fn ($span) => $span->kind === SpanKind::Consumer)
        ->values();

    expect($spans)->toHaveCount(1)
        ->and($spans[0]->attributes()['queue.job.outcome'])->toBe('abandoned')
        ->and($spans[0]->status())->toBe(SpanStatus::Error)
        ->and($spans[0]->durationMs())->toBeGreaterThanOrEqual(0.0);
});

/**
 * JobAttempted fires in a finally for EVERY attempt, after JobProcessed —
 * so the backstop must recognise an attempt that already reported one.
 */
it('counts an ordinary attempt once, though JobAttempted follows it', function () {
    $events = app('events');
    $job = abandonedTestJob();

    $events->dispatch(new JobProcessing('redis', $job));
    $events->dispatch(new JobProcessed('redis', $job));
    $events->dispatch(new JobAttempted('redis', $job));

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());

    expect($families['queue.jobs.processed']->samples[0]->value)->toBe(1.0)
        ->and($families->has('queue.jobs.abandoned'))->toBeFalse()
        ->and(Telemetry::tracer()->currentSpan())->toBeNull();
});

/**
 * The outer job is still running when a sync child's JobAttempted arrives,
 * and SyncQueue dispatches one.
 */
it('leaves the outer attempt alone when a sync child is attempted', function () {
    $events = app('events');
    $outer = abandonedTestJob();
    $child = abandonedTestJob();

    $events->dispatch(new JobProcessing('redis', $outer));

    $events->dispatch(new JobProcessing('sync', $child));
    $events->dispatch(new JobProcessed('sync', $child));
    $events->dispatch(new JobAttempted('sync', $child));

    expect(Telemetry::tracer()->currentSpan())->not->toBeNull();

    $events->dispatch(new JobProcessed('redis', $outer));

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());

    expect($families['queue.jobs.processed']->samples[0]->value)->toBe(2.0)
        ->and(Telemetry::tracer()->currentSpan())->toBeNull();
});
