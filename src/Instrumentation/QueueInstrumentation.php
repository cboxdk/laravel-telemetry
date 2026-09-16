<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Contracts\ManagesRequestState;
use Cbox\Telemetry\Native\NativeProfiler;
use Cbox\Telemetry\Native\NativeReporter;
use Cbox\Telemetry\Native\NativeUnit;
use Cbox\Telemetry\Support\Cast;
use Cbox\Telemetry\Support\CpuProfiler;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\Support\ResourceUsage;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\SpanLink;
use Cbox\Telemetry\Tracing\SpanStatus;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\QueueManager;
use Throwable;

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

    /** @var array<int, CpuProfiler> keyed by span object id */
    private array $jobProfiles = [];

    /** @var array<int, NativeUnit> keyed by span object id */
    private array $jobUnits = [];

    /**
     * Which job object owns which open unit, so the backstop below closes
     * the attempt it is told about and not somebody else's.
     *
     * @var array<int, int> job object id => span object id
     */
    private array $unitOwners = [];

    public function __construct(private readonly Container $container)
    {
        $this->completedAttempts = new \WeakMap;
    }

    /**
     * Resolved per event so Telemetry::fake() swaps take effect.
     */
    private function telemetry(): TelemetryManager
    {
        return $this->container->make(TelemetryManager::class);
    }

    private function native(): NativeProfiler
    {
        return $this->container->make(NativeProfiler::class);
    }

    /**
     * The app's own default cache — a small side channel for "the
     * previous attempt's span id", NOT the telemetry metric store. It
     * has to be something that survives across potentially different
     * worker processes (a retry can land on any worker), which rules
     * out in-memory state; the app's cache is the one thing every
     * Laravel install already has configured for exactly this shape of
     * problem. A null/array cache driver degrades this to a no-op —
     * fine, retries just go unlinked, same as no cache being reachable.
     */
    private function retryLinkCache(): CacheRepository
    {
        return $this->container->make(CacheFactory::class)->store(
            Cast::string(config('telemetry.queue.retry_link_store')) ?: null,
        );
    }

    private function retryLinkKey(string $uuid): string
    {
        return "telemetry:queue-retry-link:{$uuid}";
    }

    /**
     * The previous attempt's span, as a link — never a parent, since the
     * new attempt is a sibling (both children of the original dispatch),
     * not a continuation. Job uuids are stable across retries of the
     * same dispatch, so this is the only identifier that reliably
     * bridges attempts that may land on different worker processes.
     *
     * @return list<SpanLink>
     */
    private function retryLink(JobProcessing $event): array
    {
        if (! config('telemetry.instrument.queue_retry_links', true) || $event->job->attempts() <= 1) {
            return [];
        }

        $uuid = $event->job->uuid();

        if (! is_string($uuid) || $uuid === '') {
            return [];
        }

        $previous = FailSafe::guard(fn () => $this->retryLinkCache()->get($this->retryLinkKey($uuid)));

        if (! is_array($previous) || ! is_string($previous['traceId'] ?? null) || ! is_string($previous['spanId'] ?? null)) {
            return [];
        }

        return [new SpanLink($previous['traceId'], $previous['spanId'], ['queue.retry' => true])];
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
                    // completeJob() ends the span, records duration/memory/CPU
                    // AND increments queue.jobs.timed_out — one source, so the
                    // counter cannot drift from the spans.
                    //
                    // It has to happen here: Laravel raises JobTimedOut and
                    // then SIGKILLs the worker, so nothing else will ever close
                    // this attempt. The trace for a timed-out job — exactly the
                    // job you went looking for — was simply absent.
                    $this->completeJob(
                        $event->job->resolveName(),
                        $event->job->getQueue(),
                        'timed_out',
                        $event->connectionName === 'sync',
                        $event->job,
                    );
                });
            }

            // Every other listener here is an attempt OUTCOME, and Laravel
            // has a path with no outcome at all: a job that releases itself
            // and then throws is neither processed, nor failed, nor released
            // by the worker (Worker::handleJobException only dispatches
            // JobReleasedAfterException for a job it released itself). The
            // native unit would stay open, and the one-unit-at-a-time rule
            // would then refuse every job for the rest of the worker's life.
            //
            // JobAttempted fires in a finally for every attempt, so it is the
            // one place that can guarantee the unit is closed. Spans and
            // metrics are deliberately left alone here — an attempt with no
            // outcome has no outcome to count.
            if (class_exists(JobAttempted::class)) {
                $events->listen(JobAttempted::class, function ($event) {
                    // Scoped to the job it is telling us about. A sweep of
                    // everything open would close the OUTER job's unit the
                    // moment a job dispatched a sync child, because SyncQueue
                    // dispatches this event too — mid-flight, discarding
                    // measurements the outer job was still accumulating.
                    $this->closeAbandonedUnit($event->job);
                });
            }

            // The pid label is unique to this process — retire its series
            // when the worker stops, or every restart leaves a dead
            // queue.worker.memory.* series in the shared store forever.
            $events->listen(WorkerStopping::class, function () {
                // Last chance to ship anything. On the timeout path Laravel
                // follows this with posix_kill(SIGKILL), which runs no
                // shutdown function and no terminating callback — so without
                // this the buffered queue.jobs.timed_out counter and the span
                // above died with the process, and the metric read a flat zero
                // no matter how many jobs were timing out.
                FailSafe::guard(fn () => $this->telemetry()->flush());
            });

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
                $this->retryLink($event),
            );

            $this->jobSpans[] = $span;

            $this->telemetry()->publishTraceContext();

            // Resource capture per job — skipped for sync jobs, which run
            // inside a request that is already being measured.
            if ($event->connectionName !== 'sync' && config('telemetry.instrument.resources', true)) {
                $this->jobUsage[spl_object_id($span)] = ResourceUsage::start();
            }

            // Sync jobs run inside a request that is already a unit of work.
            // Opening a second one would abandon the outer unit in the C, so
            // the profiler refuses it — the guard here just skips the call.
            if ($event->connectionName !== 'sync') {
                $unit = $this->native()->begin('queue', $span);

                if ($unit !== null) {
                    $this->jobUnits[spl_object_id($span)] = $unit;
                    $this->unitOwners[spl_object_id($event->job)] = spl_object_id($span);
                }
            }

            // ext-excimer is the fallback for hosts without the extension —
            // the test is whether the NATIVE sampler is running, not whether
            // this job got a unit. A unit opens even where profiling is
            // unavailable, and a job refused a unit for nesting is running
            // inside one that IS sampling.
            if ($event->connectionName !== 'sync'
                && ! $this->native()->profiles()
                && config('telemetry.instrument.profiling', true)
                && $span->sampled
            ) {
                $this->jobProfiles[spl_object_id($span)] = CpuProfiler::start(
                    Cast::float(config('telemetry.profiling.period'), 0.001),
                );
            }
        });
    }

    /**
     * Attempts already closed out, keyed by the job object.
     *
     * Laravel dispatches BOTH JobFailed and JobProcessed for a single attempt
     * on two ordinary paths: a job calling $this->fail($e) (Job::fail()
     * dispatches JobFailed, fire() then returns normally and the worker raises
     * JobProcessed), and a job arriving past --tries
     * (markJobAsFailedIfAlreadyExceedsMaxAttempts fails it, then
     * `if ($job->isDeleted()) return $this->raiseAfterJobEvent(...)`).
     *
     * Counting both made queue.jobs.failed and queue.jobs.processed each +1 for
     * one attempt, so every success-rate panel overstated success in proportion
     * to the failure rate. And because completeJob() pops the span stack, the
     * spurious second call popped the OUTER job's span in a sync-inside-async
     * dispatch — ending it early and recording its duration against the inner
     * job's outcome.
     *
     * A WeakMap, not an id-keyed array: the worker is long-running, so an
     * array would grow one entry per job forever — and spl_object_id() is
     * reused once an object is collected, which would latch a LATER job by
     * accident. The map drops each entry when its job is.
     *
     * @var \WeakMap<object, true>
     */
    private \WeakMap $completedAttempts;

    private function jobProcessed(JobProcessed $event): void
    {
        $this->completeJob(
            job: $event->job->resolveName(),
            queue: $event->job->getQueue(),
            outcome: 'processed',
            sync: $event->connectionName === 'sync',
            attempt: $event->job,
        );
    }

    /**
     * The job threw but will be retried — JobProcessed/JobFailed never
     * fire for this attempt, so it must be completed here or the span
     * leaks and the attempt goes unrecorded.
     */
    private function jobReleased(JobReleasedAfterException $event): void
    {
        FailSafe::guard(function () use ($event) {
            $span = $this->currentJobSpan();
            $span?->setStatus(SpanStatus::Error, 'released for retry');

            if ($span !== null && config('telemetry.instrument.queue_retry_links', true)) {
                $uuid = $event->job->uuid();

                if (is_string($uuid) && $uuid !== '') {
                    $this->retryLinkCache()->put(
                        $this->retryLinkKey($uuid),
                        ['traceId' => $span->traceId, 'spanId' => $span->spanId],
                        Cast::int(config('telemetry.queue.retry_link_ttl'), 86400),
                    );
                }
            }
        });

        // A released attempt is reported exactly like a terminal one — the
        // worker rethrows and the handler runs after this teardown — so
        // wherever retries are configured (`queue:work --tries=3`, a job's
        // own `$tries`, Horizon's `tries`; the framework default is 1) every
        // attempt but the last produced an unattributable error record. Where
        // the event carries the throwable that caused the release, that is the
        // one being reported.
        //
        // `exception` was added to this event in Laravel v13.31.0. On 12.x and
        // on 13.0–13.30 the property does not exist at all, and reading it
        // raises an undefined-property warning that Laravel's error handler
        // turns into an ErrorException — inside a queue listener, on every
        // retry. Coalesced rather than version-tested: the attribution is a
        // bonus on versions that carry the throwable, and its absence must
        // never break the release path on the ones that do not.
        $releasedBy = $event->exception ?? null;

        if ($releasedBy instanceof Throwable) {
            $this->rememberFailureContext($releasedBy);
        }

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

        $this->rememberFailureContext($event->exception);

        $this->completeJob(
            job: $event->job->resolveName(),
            queue: $event->job->getQueue(),
            outcome: 'failed',
            sync: $event->connectionName === 'sync',
            attempt: $event->job,
        );
    }

    private function completeJob(string $job, ?string $queue, string $outcome, bool $sync, ?object $attempt = null): void
    {
        if ($attempt !== null) {
            if (isset($this->completedAttempts[$attempt])) {
                return;
            }

            $this->completedAttempts[$attempt] = true;
        }

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

                // Before end() — see the ordering note in TraceRequest.
                $unit = $this->jobUnits[spl_object_id($span)] ?? null;
                unset($this->jobUnits[spl_object_id($span)]);

                if ($unit !== null) {
                    $result = $unit->finish($this->telemetry()->tracer()->currentlySampled($span));

                    if ($result !== null) {
                        NativeReporter::report($this->telemetry(), $result, $span, [
                            'job.name' => $job,
                            'queue' => $queue ?? 'default',
                        ]);
                    }
                }

                $span->end();

                $profile = $this->jobProfiles[spl_object_id($span)] ?? null;
                unset($this->jobProfiles[spl_object_id($span)]);

                if ($profile !== null) {
                    $this->reportProfile($profile, $span->durationMs(), $job, $queue ?? 'default');
                }

                $this->telemetry()
                    ->histogram('queue.job.duration', description: 'Queue job processing duration', unit: 'ms')
                    ->record($span->durationMs(), $labels);
            }

            $this->telemetry()
                ->counter("queue.jobs.{$outcome}", 'Queue job attempts by outcome')
                ->inc(1, $labels);
        });

        if (! $sync) {
            // Worker self-report: the process' CURRENT memory after each job.
            // A distribution that drifts upward over time IS the memory leak —
            // no daemon required, the worker measures itself.
            //
            // A HISTOGRAM by queue, not a gauge by pid. The pid was an
            // unbounded label whose series were retired only on WorkerStopping,
            // which a worker killed by the OOM killer or SIGKILL never
            // dispatches — so the gauge designed to catch a leaking worker
            // leaked a permanent series precisely when the worker died of the
            // leak, each frozen at its last value with no TTL. A worker
            // recycling every 90s across 20 queues left ~1,900 dead series a
            // day. The distribution answers the same question without needing
            // to know which process asked it.
            FailSafe::guard(function () use ($queue) {
                $labels = ['queue' => $queue ?? 'default'];

                $this->telemetry()
                    ->histogram('queue.worker.memory.php', buckets: [16777216, 33554432, 67108864, 134217728, 268435456, 536870912, 1073741824, 2147483648], description: 'Worker PHP allocator usage after each job', unit: 'By')
                    ->record((float) memory_get_usage(true), $labels);

                if (($rss = ResourceUsage::currentRssBytes()) !== null) {
                    $this->telemetry()
                        ->histogram('queue.worker.memory.rss', buckets: [16777216, 33554432, 67108864, 134217728, 268435456, 536870912, 1073741824, 2147483648], description: 'Worker resident set size after each job', unit: 'By')
                        ->record((float) $rss, $labels);
                }
            });

            FailSafe::guard(function () {
                $this->telemetry()->flush();
                $this->telemetry()->resetContext();
            });
        }
    }

    /**
     * Hand this job's dimensions to whoever reports THIS exception.
     *
     * Laravel dispatches JobFailed from inside
     * Worker::handleJobException(), which then rethrows; only in
     * Worker::runJob()'s catch does the exception reach the handler and,
     * through it, this package's own reportable listener. Everything below
     * runs before that, teardown included — so the snapshot is taken here and
     * the context is still reset as it always was.
     */
    private function rememberFailureContext(Throwable $e): void
    {
        FailSafe::guard(function () use ($e): void {
            $context = $this->telemetry()->contextAttributes();

            if ($context !== []) {
                $this->telemetry()->rememberFailureContext($e, $context);
            }
        });
    }

    /**
     * Close the native unit of an attempt that never reached a completion
     * event, or whose completion threw before it got that far.
     */
    private function closeAbandonedUnit(object $job): void
    {
        $owner = spl_object_id($job);
        $spanId = $this->unitOwners[$owner] ?? null;
        unset($this->unitOwners[$owner]);

        if ($spanId === null) {
            return;
        }

        $unit = $this->jobUnits[$spanId] ?? null;
        unset($this->jobUnits[$spanId]);

        if ($unit !== null) {
            FailSafe::guard(static fn () => $unit->discard());
        }
    }

    /**
     * Everything still open, for an Octane/NativePHP worker reset where the
     * requests that opened them are gone.
     */
    private function closeAbandonedUnits(): void
    {
        $units = $this->jobUnits;

        $this->jobUnits = [];
        $this->unitOwners = [];

        foreach ($units as $unit) {
            FailSafe::guard(static fn () => $unit->discard());
        }
    }

    private function currentJobSpan(): ?Span
    {
        return $this->jobSpans === [] ? null : $this->jobSpans[array_key_last($this->jobSpans)];
    }

    private function reportProfile(CpuProfiler $profile, float $durationMs, string $job, string $queue): void
    {
        $top = $profile->stop(Cast::int(config('telemetry.profiling.top_functions'), 20));

        if ($top === null || $durationMs < Cast::float(config('telemetry.profiling.min_duration_ms'), 500.0)) {
            return;
        }

        $this->telemetry()->event('profile.captured', [
            'job.name' => $job,
            'queue' => $queue,
            'duration_ms' => round($durationMs, 2),
            'profile.source' => 'excimer',
            'profile.top_functions' => json_encode($top, JSON_UNESCAPED_SLASHES) ?: '[]',
        ]);
    }

    public function flushRequestState(): void
    {
        $this->jobSpans = [];
        $this->jobUsage = [];
        $this->jobProfiles = [];

        $this->closeAbandonedUnits();
    }
}
