<?php

declare(strict_types=1);

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

    $events = everyInstrumentedEvent()[$group];

    $caught = swallowed(function () use ($events): void {
        foreach ($events as $event) {
            // No try/catch here on purpose. Anything that escapes fails
            // the test, which is exactly the assertion.
            Event::dispatch($event);
        }
    });

    expect($caught)->not->toBeEmpty("No handler in [{$group}] even tried to reach telemetry — the dispatch did nothing, so this proves nothing.");
})->with(['cache', 'queue', 'database', 'auth', 'redis', 'lifecycle']);

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
