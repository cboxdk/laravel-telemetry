<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\SpanStatus;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;

function fakeJob(int $attempts = 1, string $uuid = 'fake-job-uuid'): Job
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\Jobs\FlakyJob');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('payload')->andReturn([]);
    $job->shouldReceive('attempts')->andReturn($attempts);
    $job->shouldReceive('uuid')->andReturn($uuid);

    return $job;
}

it('records released-for-retry attempts that fire neither processed nor failed', function () {
    $collector = new CollectingExporter;
    Telemetry::addExporter($collector);

    app('queue'); // resolve the QueueManager so the listeners register

    $events = app('events');
    $job = fakeJob();

    // Simulate the worker's event sequence for a job that throws with
    // retries remaining: JobProcessing -> JobReleasedAfterException.
    $events->dispatch(new JobProcessing('redis', $job));
    $events->dispatch(new JobReleasedAfterException('redis', $job));

    $spans = collect($collector->batches())->flatMap(fn ($batch) => $batch->spans);
    $released = $spans->firstWhere(fn ($span) => str_contains($span->name, 'FlakyJob'));

    expect($released)->not->toBeNull()
        ->and($released->hasEnded())->toBeTrue()
        ->and($released->status())->toBe(SpanStatus::Error);

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());

    expect($families)->toHaveKey('queue.jobs.released')
        ->and($families['queue.jobs.released']->samples[0]->value)->toBe(1.0)
        ->and($families)->toHaveKey('queue.job.duration');
});

it('links a retried attempt to the previous attempts span, never as a parent', function () {
    $collector = new CollectingExporter;
    Telemetry::addExporter($collector);

    app('queue'); // resolve the QueueManager so the listeners register

    $events = app('events');
    $uuid = 'retry-uuid-'.uniqid();

    // Attempt 1: processes, then released for retry.
    $events->dispatch(new JobProcessing('redis', fakeJob(attempts: 1, uuid: $uuid)));
    $events->dispatch(new JobReleasedAfterException('redis', fakeJob(attempts: 1, uuid: $uuid)));

    $spans = collect($collector->batches())->flatMap(fn ($batch) => $batch->spans)
        ->filter(fn ($span) => str_contains($span->name, 'FlakyJob'))
        ->values();

    $firstAttempt = $spans[0];

    // Attempt 2: same job uuid, attempts() now returns 2.
    $events->dispatch(new JobProcessing('redis', fakeJob(attempts: 2, uuid: $uuid)));
    $events->dispatch(new JobProcessed('redis', fakeJob(attempts: 2, uuid: $uuid)));

    $spans = collect($collector->batches())->flatMap(fn ($batch) => $batch->spans)
        ->filter(fn ($span) => str_contains($span->name, 'FlakyJob'))
        ->values();

    $secondAttempt = $spans[1];

    expect($secondAttempt->parentSpanId)->not->toBe($firstAttempt->spanId)
        ->and($secondAttempt->links())->toHaveCount(1)
        ->and($secondAttempt->links()[0]->traceId)->toBe($firstAttempt->traceId)
        ->and($secondAttempt->links()[0]->spanId)->toBe($firstAttempt->spanId);
});

it('does not link the first attempt of a job', function () {
    $collector = new CollectingExporter;
    Telemetry::addExporter($collector);

    app('queue');

    $events = app('events');
    $job = fakeJob(attempts: 1);

    $events->dispatch(new JobProcessing('redis', $job));
    $events->dispatch(new JobProcessed('redis', $job));

    $span = collect($collector->batches())->flatMap(fn ($batch) => $batch->spans)
        ->first(fn ($span) => str_contains($span->name, 'FlakyJob'));

    expect($span->links())->toBeEmpty();
});

it('can disable queue retry links', function () {
    config()->set('telemetry.instrument.queue_retry_links', false);

    $collector = new CollectingExporter;
    Telemetry::addExporter($collector);

    app('queue');

    $events = app('events');
    $uuid = 'retry-uuid-'.uniqid();

    $events->dispatch(new JobProcessing('redis', fakeJob(attempts: 1, uuid: $uuid)));
    $events->dispatch(new JobReleasedAfterException('redis', fakeJob(attempts: 1, uuid: $uuid)));
    $events->dispatch(new JobProcessing('redis', fakeJob(attempts: 2, uuid: $uuid)));

    $span = collect($collector->batches())->flatMap(fn ($batch) => $batch->spans)
        ->filter(fn ($span) => str_contains($span->name, 'FlakyJob'))
        ->last();

    expect($span->links())->toBeEmpty();
});

it('keeps nested job spans on a stack so the outer span survives', function () {
    $collector = new CollectingExporter;
    Telemetry::addExporter($collector);

    app('queue'); // resolve the QueueManager so the listeners register

    $events = app('events');

    $outer = fakeJob();
    $inner = fakeJob();

    $events->dispatch(new JobProcessing('redis', $outer));

    // A sync dispatch inside the outer job.
    $events->dispatch(new JobProcessing('sync', $inner));
    $events->dispatch(new JobProcessed('sync', $inner));

    $events->dispatch(new JobProcessed('redis', $outer));

    $spans = collect($collector->batches())->flatMap(fn ($batch) => $batch->spans)
        ->filter(fn ($span) => str_contains($span->name, 'FlakyJob'));

    expect($spans)->toHaveCount(2)
        ->and($spans->every(fn ($span) => $span->hasEnded()))->toBeTrue();

    // The inner sync span is a child of the outer consumer span.
    $byParent = $spans->keyBy(fn ($span) => $span->parentSpanId ?? 'root');

    expect($spans->pluck('traceId')->unique())->toHaveCount(1);
});
