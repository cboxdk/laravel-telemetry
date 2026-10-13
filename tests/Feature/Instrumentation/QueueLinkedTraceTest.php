<?php

declare(strict_types=1);

use Cbox\Telemetry\Contracts\StartsLinkedTrace;
use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanKind;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Events\Dispatchable as DispatchesEvents;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/*
 * Real payloads through a real worker: jobs are pushed onto a database
 * queue and processed by Laravel's Worker, so the wrapped classes
 * (CallQueuedListener, SendQueuedMailable, SendQueuedNotifications) are
 * the framework's own, not doubles that agree with the implementation.
 */

class LinkedChildJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void {}
}

class LinkedRootJob implements ShouldQueue, StartsLinkedTrace
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void {}
}

class LinkedTraceEvent
{
    use DispatchesEvents;
}

class LinkedTraceListener implements ShouldQueue, StartsLinkedTrace
{
    public function handle(LinkedTraceEvent $event): void {}
}

class LinkedTraceMail extends Mailable implements StartsLinkedTrace
{
    public function build(): self
    {
        return $this->subject('linked')->html('<p>linked</p>');
    }
}

class LinkedTraceChannel
{
    public function send(object $notifiable, Notification $notification): void {}
}

class LinkedTraceNotification extends Notification implements ShouldQueue, StartsLinkedTrace
{
    use Queueable;

    /** @return list<class-string> */
    public function via(object $notifiable): array
    {
        return [LinkedTraceChannel::class];
    }
}

beforeEach(function () {
    config()->set('database.connections.linked_traces', ['driver' => 'sqlite', 'database' => ':memory:']);
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'connection' => 'linked_traces',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
    ]);
    config()->set('queue.default', 'database');
    config()->set('queue.batching', ['database' => 'linked_traces', 'table' => 'job_batches']);
    config()->set('mail.default', 'array');

    Schema::connection('linked_traces')->create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    Schema::connection('linked_traces')->create('job_batches', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('name');
        $table->integer('total_jobs');
        $table->integer('pending_jobs');
        $table->integer('failed_jobs');
        $table->longText('failed_job_ids');
        $table->mediumText('options')->nullable();
        $table->integer('cancelled_at')->nullable();
        $table->integer('created_at');
        $table->integer('finished_at')->nullable();
    });

    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);
});

/**
 * Pop and process every job on the queue, the way `queue:work` would.
 */
function workLinkedQueue(): void
{
    $queue = app('queue')->connection('database');

    while (($job = $queue->pop('default')) !== null) {
        app('queue.worker')->process('database', $job, new WorkerOptions);
    }
}

/**
 * Dispatch inside a span that stands in for the request, and hand back
 * that span plus the consumer spans the worker produced.
 *
 * @return array{0: Span, 1: list<Span>}
 */
function dispatchLinked(Closure $dispatch): array
{
    $request = Telemetry::span('POST /orders');
    $dispatch();
    $request->end();

    workLinkedQueue();
    Telemetry::flush();

    $consumers = [];

    foreach (test()->collector->batches() as $batch) {
        foreach ($batch->spans as $span) {
            if ($span->kind === SpanKind::Consumer) {
                $consumers[] = $span;
            }
        }
    }

    return [$request, $consumers];
}

function expectLinkedTo(Span $job, Span $dispatch): void
{
    expect($job->traceId)->not->toBe($dispatch->traceId)
        ->and($job->parentSpanId)->toBeNull()
        ->and($job->links())->toHaveCount(1)
        ->and($job->links()[0]->traceId)->toBe($dispatch->traceId)
        ->and($job->links()[0]->spanId)->toBe($dispatch->spanId)
        ->and($job->links()[0]->sampled)->toBe($dispatch->sampled)
        ->and($job->links()[0]->attributes)->toBe(['queue.dispatch' => true]);
}

it('keeps an unmarked job a child of the dispatch span', function () {
    [$request, $jobs] = dispatchLinked(fn () => LinkedChildJob::dispatch());

    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]->traceId)->toBe($request->traceId)
        ->and($jobs[0]->parentSpanId)->toBe($request->spanId)
        ->and($jobs[0]->links())->toBe([]);
});

it('starts a marked job as the root of a new trace that links the dispatch span', function () {
    [$request, $jobs] = dispatchLinked(fn () => LinkedRootJob::dispatch());

    expect($jobs)->toHaveCount(1);
    expectLinkedTo($jobs[0], $request);

    // Still traceable back without walking the link.
    expect($jobs[0]->attributes()['messaging.origin.name'])->toBe('POST /orders');
});

it('links a job listed in queue.linked_traces without touching the class', function () {
    config()->set('telemetry.queue.linked_traces', [LinkedChildJob::class]);

    [$request, $jobs] = dispatchLinked(fn () => LinkedChildJob::dispatch());

    expectLinkedTo($jobs[0], $request);
});

it('matches queue.linked_traces by parent class or interface', function () {
    config()->set('telemetry.queue.linked_traces', [ShouldQueue::class]);

    [$request, $jobs] = dispatchLinked(fn () => LinkedChildJob::dispatch());

    expectLinkedTo($jobs[0], $request);
});

it('carries the dispatcher\'s unsampled flag on the link and samples the new root itself', function () {
    config()->set('telemetry.traces.sample_rate', 1.0);

    $request = Telemetry::span('POST /orders');
    Telemetry::tracer()->resample(false);
    LinkedRootJob::dispatch();
    $request->end();

    workLinkedQueue();
    Telemetry::flush();

    $job = collect($this->collector->batches())->flatMap(fn ($batch) => $batch->spans)
        ->first(fn (Span $span) => $span->kind === SpanKind::Consumer);

    // Exported at all is the proof: a continued child of an unsampled
    // dispatch would have been dropped with it.
    expect($job)->not->toBeNull()
        ->and($job->sampled)->toBeTrue()
        ->and($job->links()[0]->sampled)->toBeFalse()
        ->and($job->links()[0]->spanId)->toBe($request->spanId);
});

it('links the underlying class of a queued listener', function () {
    Event::listen(LinkedTraceEvent::class, LinkedTraceListener::class);

    [$request, $jobs] = dispatchLinked(fn () => LinkedTraceEvent::dispatch());

    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]->name)->toContain('LinkedTraceListener');
    expectLinkedTo($jobs[0], $request);
});

it('links the underlying class of a queued mailable', function () {
    [$request, $jobs] = dispatchLinked(fn () => Mail::to('ops@example.com')->queue(new LinkedTraceMail));

    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]->name)->toContain('LinkedTraceMail');
    expectLinkedTo($jobs[0], $request);
});

it('links the underlying class of a queued notification', function () {
    [$request, $jobs] = dispatchLinked(
        fn () => (new AnonymousNotifiable)->route(LinkedTraceChannel::class, 'ops')->notify(new LinkedTraceNotification),
    );

    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]->name)->toContain('LinkedTraceNotification');
    expectLinkedTo($jobs[0], $request);
});

it('links each marked job of a batch to the span that dispatched the batch', function () {
    [$request, $jobs] = dispatchLinked(fn () => Bus::batch([new LinkedRootJob, new LinkedRootJob, new LinkedChildJob])->dispatch());

    $linked = array_values(array_filter($jobs, fn (Span $span) => str_contains($span->name, 'LinkedRootJob')));
    $child = array_values(array_filter($jobs, fn (Span $span) => str_contains($span->name, 'LinkedChildJob')));

    // A batch pushes its jobs inside a transaction, so the dispatching
    // span is that db.transaction span: the unmarked job's parent is the
    // span each marked job links.
    expect($linked)->toHaveCount(2)
        ->and($child)->toHaveCount(1)
        ->and($child[0]->traceId)->toBe($request->traceId)
        ->and($linked[0]->traceId)->not->toBe($linked[1]->traceId);

    foreach ($linked as $job) {
        expect($job->traceId)->not->toBe($request->traceId)
            ->and($job->parentSpanId)->toBeNull()
            ->and($job->links())->toHaveCount(1)
            ->and($job->links()[0]->traceId)->toBe($request->traceId)
            ->and($job->links()[0]->spanId)->toBe($child[0]->parentSpanId);
    }
});

it('links a marked job in a chain to the job before it, which dispatched it', function () {
    [$request, $jobs] = dispatchLinked(fn () => Bus::chain([new LinkedChildJob, new LinkedRootJob])->dispatch());

    expect($jobs)->toHaveCount(2)
        ->and($jobs[0]->name)->toContain('LinkedChildJob')
        ->and($jobs[0]->parentSpanId)->toBe($request->spanId);
    expectLinkedTo($jobs[1], $jobs[0]);
});

it('keeps a marked job a child on the sync connection, which runs inline', function () {
    config()->set('queue.default', 'sync');

    $request = Telemetry::span('POST /orders');
    LinkedRootJob::dispatch();
    $request->end();
    Telemetry::flush();

    $job = collect($this->collector->batches())->flatMap(fn ($batch) => $batch->spans)
        ->first(fn (Span $span) => $span->kind === SpanKind::Consumer);

    expect($job->traceId)->toBe($request->traceId)
        ->and($job->parentSpanId)->toBe($request->spanId)
        ->and($job->links())->toBe([]);
});

it('keeps both links on a retried linked attempt: the dispatch and the previous attempt', function () {
    app('queue'); // resolve the QueueManager so the listeners register

    $events = app('events');
    $uuid = 'linked-retry-'.uniqid();

    $attempt = function (int $attempts) use ($uuid): Job {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn(LinkedRootJob::class);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('getQueue')->andReturn('default');
        $job->shouldReceive('attempts')->andReturn($attempts);
        $job->shouldReceive('uuid')->andReturn($uuid);
        $job->shouldReceive('payload')->andReturn([
            'displayName' => LinkedRootJob::class,
            'data' => ['commandName' => LinkedRootJob::class],
            'telemetry' => ['traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'],
        ]);

        return $job;
    };

    $events->dispatch(new JobProcessing('redis', $attempt(1)));
    $events->dispatch(new JobReleasedAfterException('redis', $attempt(1)));
    $events->dispatch(new JobProcessing('redis', $attempt(2)));
    $events->dispatch(new JobProcessed('redis', $attempt(2)));
    Telemetry::flush();

    $spans = collect($this->collector->batches())->flatMap(fn ($batch) => $batch->spans)
        ->filter(fn (Span $span) => $span->kind === SpanKind::Consumer)
        ->values();

    expect($spans)->toHaveCount(2)
        ->and($spans[1]->links())->toHaveCount(2)
        ->and($spans[1]->links()[0]->spanId)->toBe('b7ad6b7169203331')
        ->and($spans[1]->links()[0]->attributes)->toBe(['queue.dispatch' => true])
        ->and($spans[1]->links()[1]->spanId)->toBe($spans[0]->spanId)
        ->and($spans[1]->links()[1]->attributes)->toBe(['queue.retry' => true]);
});
