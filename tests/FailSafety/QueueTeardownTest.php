<?php

declare(strict_types=1);

use Cbox\Telemetry\Contracts\NativeRuntime;
use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Instrumentation\QueueInstrumentation;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Testing\CollectingExporter;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Teardown after a job runs inside a guard, so a store that is down
 * cannot fail the job. But the guard only swallows the throw — whatever
 * the failed teardown was still holding stays held, and a queue worker
 * runs for hours. A leak that only appears while Redis is down is a
 * leak nobody sees in review and everybody sees at 3am.
 */
function crashingRuntime(): NativeRuntime
{
    return new class implements NativeRuntime
    {
        public function available(): bool
        {
            return true;
        }

        public function version(): ?string
        {
            return '1.0.0';
        }

        public function status(): array
        {
            return [];
        }

        public function begin(array $context): int
        {
            return 42;
        }

        public function finish(int $handle, bool $includeProfile = false, bool $includeStacks = false): array
        {
            throw new RuntimeException('the extension crashed closing the unit');
        }

        public function drainCrashes(int $max = 32): array
        {
            return [];
        }
    };
}

function teardownJob(int $i): Job
{
    return new class($i) implements Job
    {
        public function __construct(private int $i) {}

        public function resolveName(): string
        {
            return 'App\Jobs\Ship';
        }

        public function getName(): string
        {
            return 'App\Jobs\Ship';
        }

        public function resolveQueuedJobClass(): string
        {
            return 'App\Jobs\Ship';
        }

        public function getQueue(): string
        {
            return 'default';
        }

        public function isReleased(): bool
        {
            return false;
        }

        public function payload(): array
        {
            return [];
        }

        public function attempts(): int
        {
            return 1;
        }

        public function uuid(): string
        {
            return 'uuid-'.$this->i;
        }

        public function maxTries(): ?int
        {
            return 1;
        }

        public function getJobId(): string
        {
            return (string) $this->i;
        }

        public function getRawBody(): string
        {
            return '{}';
        }

        public function fire(): void {}

        public function release($delay = 0): void {}

        public function isReleasedAfterException(): bool
        {
            return false;
        }

        public function delete(): void {}

        public function isDeleted(): bool
        {
            return false;
        }

        public function isDeletedOrReleased(): bool
        {
            return false;
        }

        public function hasFailed(): bool
        {
            return false;
        }

        public function markAsFailed(): void {}

        public function fail($e = null): void {}

        public function maxExceptions(): ?int
        {
            return null;
        }

        public function backoff(): ?int
        {
            return null;
        }

        public function retryUntil(): ?int
        {
            return null;
        }

        public function timeout(): ?int
        {
            return null;
        }

        public function getConnectionName(): string
        {
            return 'redis';
        }

        public function getQueueableConnection(): string
        {
            return 'redis';
        }

        public function setJobId($id): void {}
    };
}

it('holds nothing after a teardown the extension made fail', function () {
    // Teardown runs inside a guard, so the job survives. But the guard
    // only swallows the throw — everything the teardown had not yet
    // released stays held, and a queue worker runs for hours.
    //
    // The metric writes are each guarded on their own, so a dead store
    // cannot abort teardown. The native extension can: it ships and
    // upgrades separately, and closing its unit is a plain call.
    config([
        'telemetry.native.enabled' => true,
        'telemetry.instrument.resources' => true,
        'telemetry.instrument.resources_process' => true,
        'telemetry.instrument.profiling' => true,
    ]);

    app()->instance(NativeRuntime::class, crashingRuntime());

    // Something has to hold the spans. Without a collector each one is
    // freed as the next job starts, PHP reuses its object id, and an
    // orphaned entry is silently overwritten by the next job's — the
    // leak would hide itself in a test and not in a worker, where the
    // spans stay buffered until the flush.
    Telemetry::addExporter(new CollectingExporter);

    $failures = 0;
    FailSafe::handleExceptionsUsing(function () use (&$failures): void {
        $failures++;
    });

    app('queue');
    $events = app('events');

    for ($i = 0; $i < 20; $i++) {
        $job = teardownJob($i);
        $events->dispatch(new JobProcessing('redis', $job));

        $events->dispatch(new JobProcessed('redis', $job));
    }

    $instrumentation = app(QueueInstrumentation::class);
    $held = [];

    foreach (['jobSpans', 'jobUsage', 'jobProfiles', 'jobUnits', 'attemptSpans'] as $name) {
        $property = new ReflectionProperty(QueueInstrumentation::class, $name);
        $held[$name] = count((array) $property->getValue($instrumentation));
    }

    FailSafe::handleExceptionsUsing(null);

    // The premise: the teardown really did fail. Without this the test
    // would pass on a store that quietly accepted every write.
    expect($failures)->toBeGreaterThan(0)
        ->and($held)->toBe([
            'jobSpans' => 0,
            'jobUsage' => 0,
            'jobProfiles' => 0,
            'jobUnits' => 0,
            'attemptSpans' => 0,
        ]);
});

it('holds nothing after a teardown that could not even resolve telemetry', function () {
    // The harsher order, and the one that survived the first fix:
    // the job starts against a healthy manager, the binding then
    // throws, and the very first thing teardown does — classifying the
    // labels — resolves it. Release has to come before that, or every
    // map still holds the span. JobAttempted is the backstop, and it
    // has to clear the same maps.
    config([
        'telemetry.instrument.resources' => true,
        'telemetry.instrument.resources_process' => true,
        'telemetry.instrument.profiling' => true,
    ]);

    Telemetry::addExporter(new CollectingExporter);

    app('queue');
    $events = app('events');
    $job = teardownJob(1);

    $events->dispatch(new JobProcessing('redis', $job));

    app()->forgetInstance(TelemetryManager::class);
    Telemetry::clearResolvedInstances();
    app()->bind(TelemetryManager::class, static fn () => throw new RuntimeException('the manager is gone'));

    FailSafe::handleExceptionsUsing(fn () => null);

    $events->dispatch(new JobProcessed('redis', $job));
    $events->dispatch(new JobAttempted('redis', $job));

    FailSafe::handleExceptionsUsing(null);

    $instrumentation = app(QueueInstrumentation::class);
    $held = [];

    foreach (['jobSpans', 'jobUsage', 'jobProfiles', 'jobUnits', 'attemptSpans'] as $name) {
        $property = new ReflectionProperty(QueueInstrumentation::class, $name);
        $held[$name] = count((array) $property->getValue($instrumentation));
    }

    expect($held)->toBe([
        'jobSpans' => 0,
        'jobUsage' => 0,
        'jobProfiles' => 0,
        'jobUnits' => 0,
        'attemptSpans' => 0,
    ]);
});
