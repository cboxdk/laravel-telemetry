<?php

declare(strict_types=1);

use Cbox\Telemetry\Contracts\MetricStore;
use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Failed as AuthFailed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\GenericUser;
use Illuminate\Cache\Events\CacheFailedOver;
use Illuminate\Cache\Events\CacheFlushed;
use Illuminate\Cache\Events\CacheFlushFailed;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheLocksFlushed;
use Illuminate\Cache\Events\CacheLocksFlushFailed;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Cache\Events\KeyForgetFailed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\RetrievingManyKeys;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Cache\Events\WritingManyKeys;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledTask;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\DatabaseBusy;
use Illuminate\Database\Events\DatabaseRefreshed;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationSkipped;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Events\MaintenanceModeDisabled;
use Illuminate\Foundation\Events\MaintenanceModeEnabled;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Redis\Events\CommandExecuted as RedisCommandExecuted;
use Illuminate\Redis\Events\CommandFailed as RedisCommandFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Laravel\Horizon\Events\JobsMigrated;
use Laravel\Horizon\Events\LongWaitDetected;
use Laravel\Horizon\Events\MasterSupervisorLooped;
use Laravel\Horizon\Events\MasterSupervisorOutOfMemory;
use Laravel\Horizon\Events\SupervisorLooped;
use Laravel\Horizon\Events\SupervisorOutOfMemory;
use Laravel\Horizon\Events\SupervisorProcessRestarting;
use Laravel\Horizon\Events\WorkerProcessRestarting;
use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\Supervisor;
use Laravel\Horizon\SupervisorOptions;
use Laravel\Horizon\SupervisorProcess;
use Laravel\Horizon\WorkerProcess;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Telemetry must never throw into the application.
 *
 * This is the one property that matters more than any signal the package
 * collects: a dashboard going blind is an inconvenience, and an
 * observability package turning a working request into a 500 is an
 * outage it caused itself. Reading the code for `FailSafe::guard` proves
 * only that somebody remembered; this proves it by breaking the package
 * on purpose and dispatching everything the framework can dispatch.
 *
 * Two things are asserted per event, and the second matters as much as
 * the first: nothing escaped, AND the guard is what caught it. Without
 * that second check a listener that silently never ran would pass.
 */
function brokenTelemetry(): void
{
    // Every handler reaches the manager through the container. Making
    // resolution itself throw breaks them at the first thing they do,
    // which is the deepest place a single switch can reach.
    // forgetInstance first: the manager is a singleton and boot may
    // already have resolved it, in which case rebinding alone leaves the
    // cached instance in place and nothing breaks at all — a test that
    // proves nothing while looking green.
    app()->forgetInstance(TelemetryManager::class);
    Telemetry::clearResolvedInstances();

    app()->bind(TelemetryManager::class, static function (): TelemetryManager {
        throw new RuntimeException('the telemetry backend is on fire');
    });
}

/**
 * Break what the manager is BUILT from, rather than the manager itself.
 *
 * Rebinding the manager to a thrower replaces the provider's own
 * fail-safe wrapper, so it tests the handlers' guards and nothing else.
 * The realistic failure is a dependency: a store whose extension is
 * missing, a redactor with a broken config, a connection that cannot
 * be made. That is what has to leave the application standing.
 */
function brokenTelemetryDependency(): void
{
    app()->forgetInstance(TelemetryManager::class);
    app()->forgetInstance(MetricStore::class);
    Telemetry::clearResolvedInstances();

    app()->bind(MetricStore::class, static function (): MetricStore {
        throw new RuntimeException('the metric store cannot be built here');
    });
}

/** @return list<Throwable> */
function swallowed(Closure $work): array
{
    $caught = [];
    FailSafe::handleExceptionsUsing(static function (Throwable $e) use (&$caught): void {
        $caught[] = $e;
    });

    try {
        $work();
    } finally {
        FailSafe::handleExceptionsUsing(null);
    }

    return $caught;
}

function hostileJob(): Job
{
    // A queue driver that is not Laravel's. Every one of these is a call
    // into third-party code from inside a listener, and each used to sit
    // outside every guard as an argument expression.
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andThrow(new RuntimeException('this driver does not do names'));
    $job->shouldReceive('getQueue')->andThrow(new RuntimeException('nor queues'));
    $job->shouldReceive('isReleased')->andThrow(new BadMethodCallException('nor that'));
    $job->shouldReceive('payload')->andReturn([]);
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('uuid')->andReturn(null);
    $job->shouldReceive('maxTries')->andReturn(1);

    return $job;
}

/** @return array<string, list<object>> */
function everyInstrumentedEvent(): array
{
    $user = new GenericUser(['id' => 7]);
    $migration = new class extends Migration {};
    $throwable = new RuntimeException('something in the application broke');
    $redis = Mockery::mock(Illuminate\Redis\Connections\Connection::class);
    $redis->shouldReceive('getName')->andReturn('cache');

    return [
        'cache' => [
            new CacheHit('redis', 'k', 'v'),
            new CacheMissed('redis', 'k'),
            new KeyWritten('redis', 'k', 'v'),
            new KeyForgotten('redis', 'k'),
            new KeyWriteFailed('redis', 'k', 'v'),
            new KeyForgetFailed('redis', 'k'),
            new CacheFlushed('redis'),
            new RetrievingKey('redis', 'k'),
            new RetrievingManyKeys('redis', ['a', 'b']),
            new WritingKey('redis', 'k', 'v'),
            new WritingManyKeys('redis', ['a'], ['v']),
            new ForgettingKey('redis', 'k'),
            new CacheFailedOver('redis', $throwable),
            new CacheFlushFailed('redis'),
            new CacheLocksFlushed('redis'),
            new CacheLocksFlushFailed('redis'),
        ],
        'queue' => [
            new JobProcessing('redis', hostileJob()),
            new JobProcessed('redis', hostileJob()),
            new JobReleasedAfterException('redis', hostileJob()),
            new JobFailed('redis', hostileJob(), $throwable),
            new JobTimedOut('redis', hostileJob()),
            new JobAttempted('redis', hostileJob(), $throwable),
            new JobQueued('redis', 'default', 'id', null, '{}', 0),
            new WorkerStopping(0),
            new QueueBusy('redis', 'default', 9_000),
        ],
        'database' => [
            new DatabaseBusy('mysql', 140),
            new MigrationsStarted('up'),
            new MigrationStarted($migration, 'up', 'x.php'),
            new MigrationEnded($migration, 'up', 'x.php'),
            new MigrationsEnded('up'),
            new MigrationSkipped('x.php'),
            new DatabaseRefreshed('mysql', false),
        ],
        'auth' => [
            new Login('web', $user, false),
            new Logout('web', $user),
            new AuthFailed('web', $user, []),
            new Lockout(request()),
            new PasswordReset($user),
            new Registered($user),
            new Verified($user),
            new OtherDeviceLogout('web', $user),
            new PasswordResetLinkSent($user),
            new CurrentDeviceLogout('web', $user),
        ],
        'redis' => [
            new RedisCommandExecuted('get', ['k'], 1.2, $redis),
            new RedisCommandFailed('get', ['k'], $throwable, $redis),
        ],
        'lifecycle' => [
            new MaintenanceModeEnabled,
            new MaintenanceModeDisabled,
            new CommandStarting('queue:work', new ArrayInput([]), new NullOutput),
            new CommandFinished('queue:work', new ArrayInput([]), new NullOutput, 0),
        ],
        'schedule' => [
            new ScheduledTaskStarting(hostileScheduledTask()),
            new ScheduledTaskFinished(hostileScheduledTask(), 1.5),
            new ScheduledTaskFailed(hostileScheduledTask(), $throwable),
            new ScheduledTaskSkipped(hostileScheduledTask()),
        ],
        'horizon' => [
            new SupervisorLooped(hostileSupervisor()),
            new MasterSupervisorLooped(hostileMasterSupervisor()),
            new LongWaitDetected('redis', 'emails', 45),
            new WorkerProcessRestarting(Mockery::mock(WorkerProcess::class)->makePartial()),
            new SupervisorProcessRestarting(Mockery::mock(SupervisorProcess::class)->makePartial()),
            new SupervisorOutOfMemory(hostileSupervisor()),
            new MasterSupervisorOutOfMemory(hostileMasterSupervisor()),
            new JobsMigrated('redis', 'emails', 12),
        ],
    ];
}

function hostileScheduledTask(): ScheduledTask
{
    return app(Schedule::class)->command('inspire')->everyMinute();
}

function hostileSupervisor(): Supervisor
{
    $supervisor = Mockery::mock(Supervisor::class)->makePartial();
    $supervisor->name = 'supervisor-1';
    $supervisor->options = new SupervisorOptions('supervisor-1', 'redis', 'default');
    $supervisor->working = true;
    $supervisor->shouldReceive('totalProcessCount')->andReturn(3);

    return $supervisor;
}

function hostileMasterSupervisor(): MasterSupervisor
{
    $master = Mockery::mock(MasterSupervisor::class)->makePartial();
    $master->name = 'master-1';
    $master->working = true;
    $master->supervisors = collect([hostileSupervisor()]);

    return $master;
}

/**
 * Events that record only local state — a start time, a pending key —
 * and never call telemetry at all.
 *
 * Named rather than tolerated, so the per-event assertion below stays
 * exact: an event that stops being instrumented moves into this list
 * deliberately, in a diff someone reads, instead of disappearing into
 * a group-wide "something happened".
 *
 * @return list<class-string>
 */
function eventsThatCannotReachTelemetryHere(): array
{
    return [
        // The cache "before" events open a pending timing; the paired
        // outcome event is what reaches telemetry.
        RetrievingKey::class,
        RetrievingManyKeys::class,
        WritingKey::class,
        WritingManyKeys::class,
        ForgettingKey::class,
        // Same shape: the duration is recorded on MigrationEnded.
        MigrationStarted::class,
        // Fires in a `finally` after JobProcessed/JobFailed, and closes
        // an attempt only when one is still open.
        JobAttempted::class,
        // Dispatches are counted in the payload callback, which sees
        // every dispatch including the ones that never reach a queue.
        JobQueued::class,
        // Opens the task's span; the metrics are on the outcome.
        ScheduledTaskStarting::class,
        // Not state-only, but inert HERE: CommandStarting could not open
        // a span against a backend that was already broken, so there is
        // nothing for this to close. Covered on its own below, where the
        // command starts healthy and the backend dies underneath it —
        // which is the order it happens in.
        CommandFinished::class,
    ];
}

it('swallows its own failure rather than failing the application', function (string $group): void {
    // Resolving `queue` is what arms the job listeners: the provider
    // defers them until the application asks for the queue at all, which
    // is the passivity rule doing its job. The optional instrumentations
    // are switched on by HostileTestCase, before boot — see the note in
    // Pest.php for why setting them here would not work.
    app('queue');

    brokenTelemetry();

    $unreached = [];

    foreach (everyInstrumentedEvent()[$group] as $event) {
        // Per event, not per group. A group-wide assertion passes as
        // soon as ONE of a dozen events reaches telemetry and says
        // nothing about the other eleven — and an event with no
        // listener at all is exactly what it would hide.
        //
        // The throttle is flushed between them for the same reason: two
        // events handled by the same guard would otherwise report once,
        // and the second would look unreached.
        FailSafe::flush();

        $caught = swallowed(function () use ($event): void {
            // No try/catch here on purpose. Anything that escapes fails
            // the test, which is exactly the assertion.
            Event::dispatch($event);
        });

        if ($caught === [] && ! in_array($event::class, eventsThatCannotReachTelemetryHere(), true)) {
            $unreached[] = $event::class;
        }
    }

    expect($unreached)->toBe([], sprintf(
        'These [%s] events never reached telemetry, so nothing about them was proved: %s',
        $group,
        implode(', ', $unreached),
    ));
})->with(['cache', 'queue', 'database', 'auth', 'redis', 'lifecycle', 'schedule', 'horizon']);

it('finishes a command whose backend died while it was running', function (): void {
    // The realistic order, and the one the group run cannot reach: the
    // command starts against a healthy backend, opens a span, and the
    // backend is gone by the time it ends. An artisan command that dies
    // in its own teardown is a failed deploy step or a cron job that
    // reports failure for work it actually did.
    $starting = new CommandStarting('queue:work', new ArrayInput([]), new NullOutput);
    $finished = new CommandFinished('queue:work', new ArrayInput([]), new NullOutput, 0);

    Event::dispatch($starting);

    brokenTelemetry();

    $caught = swallowed(function () use ($finished): void {
        Event::dispatch($finished);
    });

    expect($caught)->not->toBeEmpty('CommandFinished never reached telemetry, so this proves nothing.');
});

it('survives a query event with a connection that answers nothing', function (): void {
    brokenTelemetry();

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getName')->andReturn('mysql');
    $connection->shouldReceive('getDriverName')->andThrow(new RuntimeException('no driver'));

    $caught = swallowed(function () use ($connection): void {
        Event::dispatch(new QueryExecuted('select 1', [], 1.0, $connection));
    });

    expect($caught)->not->toBeEmpty();
});

it('serves the request when the manager cannot be built at all', function (): void {
    // The failure the event-dispatch suite above cannot see. TraceRequest
    // takes the manager by CONSTRUCTOR injection, so the container
    // resolves it before `handle()` and every guard inside it — a
    // binding that throws fails the request before this package has had
    // a chance to swallow anything.
    //
    // Same for the deferred filesystem and broadcasting extenders, and
    // for the queue payload callback, which PendingDispatch::__destruct()
    // can reach. Thirty-one guarded call sites do not help when the
    // constructor is the thing that throws.
    Route::middleware('web')->get('/orders', fn () => 'ok');

    brokenTelemetryDependency();

    $this->get('/orders')->assertOk()->assertSee('ok');
});

it('records nothing rather than half of something when the manager is broken', function (): void {
    // "Telemetry is off" is the policy, so the fallback must be inert
    // rather than partly wired — a manager that still holds an exporter
    // would ship empty batches forever.
    brokenTelemetryDependency();

    expect(app(TelemetryManager::class)->enabled())->toBeFalse();

    // And every ordinary call on it has to be safe, because the whole
    // package will keep calling one.
    app(TelemetryManager::class)->counter('orders.created')->inc();
    app(TelemetryManager::class)->flush();

    expect(app(TelemetryManager::class)->collect())->toBe([]);
});
