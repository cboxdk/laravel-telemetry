<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Tracing\Span;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;

/**
 * Laravel lets an application name a queue at dispatch time, and
 * `->onQueue("tenant-{$id}")` is an ordinary thing to write. Left
 * unclassified that is one permanent series per tenant on every queue
 * metric — several of them histograms — which on a platform with
 * thousands of tenants is not a slow dashboard but a metrics backend
 * falling over.
 */
function tenantJob(string $queue): Job
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\Jobs\Ship');
    $job->shouldReceive('getQueue')->andReturn($queue);
    $job->shouldReceive('isReleased')->andReturn(false);
    $job->shouldReceive('payload')->andReturn([]);
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('uuid')->andReturn(null);
    $job->shouldReceive('maxTries')->andReturn(1);

    return $job;
}

beforeEach(function () {
    Telemetry::fake();
    app('queue');
});

it('collapses per-tenant queues into one series on the metrics', function () {
    Telemetry::classifyQueuesUsing(
        static fn (string $queue): string => str_starts_with($queue, 'tenant-') ? 'tenant' : $queue,
    );

    foreach (['tenant-1', 'tenant-2', 'tenant-3'] as $queue) {
        $job = tenantJob($queue);
        Event::dispatch(new JobProcessing('redis', $job));
        Event::dispatch(new JobProcessed('redis', $job));
    }

    Telemetry::recordedMetrics('queue.jobs.processed')
        ->assertLabelValues('queue', ['tenant'])
        ->assertSeriesCount(1);
});

it('keeps the real queue name on the span, where it costs nothing', function () {
    Telemetry::classifyQueuesUsing(static fn (): string => 'tenant');

    $job = tenantJob('tenant-42');
    Event::dispatch(new JobProcessing('redis', $job));
    Event::dispatch(new JobProcessed('redis', $job));

    // Per-occurrence, the real name is what you need when reading one
    // trace — and a span label is not a series.
    Telemetry::assertSpanRecorded(
        'App\Jobs\Ship process',
        fn (Span $s): bool => ($s->attributes()['messaging.destination.name'] ?? null) === 'tenant-42',
    );
});

it('labels a queue `other` when the classifier declines it', function () {
    // Unlike the host classifier this one cannot drop the series:
    // dropping a queue would silently remove work the application
    // actually did from its own throughput numbers.
    Telemetry::classifyQueuesUsing(static fn (): ?string => null);

    $job = tenantJob('tenant-7');
    Event::dispatch(new JobProcessing('redis', $job));
    Event::dispatch(new JobProcessed('redis', $job));

    Telemetry::recordedMetrics('queue.jobs.processed')->assertLabelValues('queue', ['other']);
});

it('leaves the name alone when nobody configured a classifier', function () {
    $job = tenantJob('emails');
    Event::dispatch(new JobProcessing('redis', $job));
    Event::dispatch(new JobProcessed('redis', $job));

    Telemetry::recordedMetrics('queue.jobs.processed')->assertLabelValues('queue', ['emails']);
});
