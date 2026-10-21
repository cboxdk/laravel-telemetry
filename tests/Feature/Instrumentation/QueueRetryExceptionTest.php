<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanStatus;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;

/**
 * An attempt that threw and was released for retry used to end as a red
 * span saying only "released for retry": no exception event, no
 * `error.type`, nothing to say what went wrong. Only a terminal JobFailed
 * attached the exception, so a job retried many times showed many red spans
 * and nothing to click into.
 *
 * The events below follow `Worker::handleJobException`: a release is
 * JobExceptionOccurred then JobReleasedAfterException; a terminal failure is
 * JobFailed then JobExceptionOccurred.
 */
function chargeCardJob(string $uuid, int $attempts = 1, string $name = 'App\Jobs\ChargeCard'): Job
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn($name);
    $job->shouldReceive('isReleased')->andReturn(false);
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('payload')->andReturn([]);
    $job->shouldReceive('attempts')->andReturn($attempts);
    $job->shouldReceive('uuid')->andReturn($uuid);

    return $job;
}

/**
 * @return list<Span>
 */
function chargeCardSpans(CollectingExporter $collector, string $name = 'ChargeCard'): array
{
    return collect($collector->batches())
        ->flatMap(fn ($batch) => $batch->spans)
        ->filter(fn (Span $span) => str_contains($span->name, $name))
        ->values()
        ->all();
}

function attemptExceptionEvents(Span $span): array
{
    return array_values(array_filter($span->events(), fn ($event) => $event->name === 'exception'));
}

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);

    app('queue'); // resolve the QueueManager so the listeners register
});

it('puts the exception on an attempt that was released for retry', function () {
    $events = app('events');
    $job = chargeCardJob('charge-1');
    $e = new RuntimeException('card declined');

    $events->dispatch(new JobProcessing('redis', $job));
    $events->dispatch(new JobExceptionOccurred('redis', $job, $e));
    $events->dispatch(new JobReleasedAfterException('redis', $job, 10, $e));

    [$span] = chargeCardSpans($this->collector);
    $exceptions = attemptExceptionEvents($span);

    expect($exceptions)->toHaveCount(1)
        ->and($exceptions[0]->attributes['exception.type'])->toBe(RuntimeException::class)
        ->and($exceptions[0]->attributes['exception.message'])->toBe('card declined')
        ->and($span->attributes()['error.type'] ?? null)->toBe(RuntimeException::class)
        ->and($span->status())->toBe(SpanStatus::Error)
        ->and($span->statusDescription())->toBe('card declined');
});

it('puts the exception on a released attempt when the release event carries none', function () {
    // Laravel 12 and 13.0-13.30: JobReleasedAfterException has no exception,
    // so JobExceptionOccurred is the only place the throwable arrives.
    $events = app('events');
    $job = chargeCardJob('charge-2');
    $e = new RuntimeException('card declined');

    $events->dispatch(new JobProcessing('redis', $job));
    $events->dispatch(new JobExceptionOccurred('redis', $job, $e));
    $events->dispatch(new JobReleasedAfterException('redis', $job));

    [$span] = chargeCardSpans($this->collector);

    expect(attemptExceptionEvents($span))->toHaveCount(1)
        ->and($span->attributes()['error.type'] ?? null)->toBe(RuntimeException::class)
        ->and($span->statusDescription())->toBe('card declined');
});

it('puts the exception on a released attempt from the release event alone', function () {
    $events = app('events');
    $job = chargeCardJob('charge-3');

    $events->dispatch(new JobProcessing('redis', $job));
    $events->dispatch(new JobReleasedAfterException('redis', $job, 10, new RuntimeException('card declined')));

    [$span] = chargeCardSpans($this->collector);

    expect(attemptExceptionEvents($span))->toHaveCount(1)
        ->and($span->attributes()['error.type'] ?? null)->toBe(RuntimeException::class)
        ->and($span->statusDescription())->toBe('card declined');
})->skip(
    fn () => ! property_exists(JobReleasedAfterException::class, 'exception'),
    'JobReleasedAfterException carries the exception from Laravel 13.31',
);

it('records exactly one exception per attempt for a job released and then failed', function () {
    $events = app('events');
    $first = new RuntimeException('card declined');
    $last = new RuntimeException('card declined again');

    // Attempt 1 throws and is released.
    $attempt = chargeCardJob('charge-4', attempts: 1);
    $events->dispatch(new JobProcessing('redis', $attempt));
    $events->dispatch(new JobExceptionOccurred('redis', $attempt, $first));
    $events->dispatch(new JobReleasedAfterException('redis', $attempt, 10, $first));

    // Attempt 2 throws and fails terminally.
    $attempt = chargeCardJob('charge-4', attempts: 2);
    $events->dispatch(new JobProcessing('redis', $attempt));
    $events->dispatch(new JobFailed('redis', $attempt, $last));
    $events->dispatch(new JobExceptionOccurred('redis', $attempt, $last));

    $spans = chargeCardSpans($this->collector);

    expect($spans)->toHaveCount(2)
        ->and(attemptExceptionEvents($spans[0]))->toHaveCount(1)
        ->and(attemptExceptionEvents($spans[0])[0]->attributes['exception.message'])->toBe('card declined')
        ->and($spans[0]->statusDescription())->toBe('card declined')
        ->and(attemptExceptionEvents($spans[1]))->toHaveCount(1)
        ->and(attemptExceptionEvents($spans[1])[0]->attributes['exception.message'])->toBe('card declined again')
        ->and($spans[1]->statusDescription())->toBe('card declined again');
});

it('keeps a failed sync child exception off the outer job span', function () {
    // The child's JobExceptionOccurred arrives AFTER its JobFailed closed it,
    // while the outer job is still the top of the stack.
    $events = app('events');
    $outer = chargeCardJob('outer-1', name: 'App\Jobs\SettleInvoices');
    $inner = chargeCardJob('inner-1');
    $e = new RuntimeException('card declined');

    $events->dispatch(new JobProcessing('redis', $outer));
    $events->dispatch(new JobProcessing('sync', $inner));
    $events->dispatch(new JobFailed('sync', $inner, $e));
    $events->dispatch(new JobExceptionOccurred('sync', $inner, $e));
    $events->dispatch(new JobProcessed('redis', $outer));

    [$outerSpan] = chargeCardSpans($this->collector, 'SettleInvoices');

    expect(attemptExceptionEvents($outerSpan))->toBeEmpty()
        ->and($outerSpan->status())->toBe(SpanStatus::Ok);
});

it('puts the exception on an attempt that released itself and then threw', function () {
    // No outcome event at all: only JobExceptionOccurred and JobAttempted.
    $events = app('events');
    $job = chargeCardJob('charge-7');

    $events->dispatch(new JobProcessing('redis', $job));
    $events->dispatch(new JobExceptionOccurred('redis', $job, new RuntimeException('card declined')));
    $events->dispatch(new JobAttempted('redis', $job));

    [$span] = chargeCardSpans($this->collector);

    expect(attemptExceptionEvents($span))->toHaveCount(1)
        ->and($span->attributes()['queue.job.outcome'] ?? null)->toBe('abandoned')
        ->and($span->statusDescription())->toBe('card declined');
});

it('says released for retry when the release came with no exception', function () {
    $events = app('events');
    $job = chargeCardJob('charge-5');

    $events->dispatch(new JobProcessing('redis', $job));
    $events->dispatch(new JobReleasedAfterException('redis', $job));

    [$span] = chargeCardSpans($this->collector);

    expect(attemptExceptionEvents($span))->toBeEmpty()
        ->and($span->status())->toBe(SpanStatus::Error)
        ->and($span->statusDescription())->toBe('released for retry');
});

it('records no exception on a job that succeeds', function () {
    $events = app('events');
    $job = chargeCardJob('charge-6');

    $events->dispatch(new JobProcessing('redis', $job));
    $events->dispatch(new JobProcessed('redis', $job));

    [$span] = chargeCardSpans($this->collector);

    expect(attemptExceptionEvents($span))->toBeEmpty()
        ->and($span->attributes())->not->toHaveKey('error.type')
        ->and($span->status())->toBe(SpanStatus::Ok);
});
