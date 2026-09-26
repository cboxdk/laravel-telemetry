<?php

declare(strict_types=1);

use Cbox\Telemetry\Instrumentation\RedisInstrumentation;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;

/**
 * A manager with some connections already open and a record of anything
 * the instrumentation asks it to open.
 *
 * @param  array<string, object>  $open
 */
function redisManager(array $open, array &$opened): RedisManager
{
    $redis = Mockery::mock(RedisManager::class);
    $redis->shouldReceive('enableEvents')->andReturnNull();
    $redis->shouldReceive('connections')->andReturn($open);
    $redis->shouldReceive('connection')->andReturnUsing(function ($name) use (&$opened) {
        $opened[] = $name;

        return Mockery::mock();
    });

    return $redis;
}

function redisConnection(): Connection
{
    // The real class, because the retro-fit guards on
    // `method_exists(…, 'setEventDispatcher')` — a bare Mockery double
    // answers every call and declares none of them.
    return Mockery::mock(Connection::class);
}

it('opens no connection at boot, whichever signals are wanted', function (bool $commands, bool $failures) {
    // Boot hygiene: the service provider does registrations, not I/O. An
    // earlier version read `database.redis` and called `connection()` on
    // every name, which dials Redis while the application is still
    // booting — for command spans it was the price of an opt-in, and for
    // failures, which are on by default, it would have been everyone's.
    config()->set('database.redis', [
        'client' => 'phpredis',
        'default' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0],
        'sessions' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 1],
    ]);

    $opened = [];
    $this->app->instance('redis', redisManager([], $opened));

    (new RedisInstrumentation($this->app))->register($this->app->make('events'), commands: $commands, failures: $failures);

    expect($opened)->toBeEmpty();
})->with([
    'commands only' => [true, false],
    'failures only' => [false, true],
    'both' => [true, true],
]);

it('arms the connections the application already opened', function () {
    // The point of the retro-fit: a connection resolved before we booted
    // has no dispatcher, so it fires no events and its failures are
    // invisible — which is the one thing failure instrumentation exists
    // for.
    $cache = redisConnection();
    $cache->shouldReceive('setEventDispatcher')->once();

    $opened = [];
    $this->app->instance('redis', redisManager(['cache' => $cache], $opened));

    (new RedisInstrumentation($this->app))->register($this->app->make('events'), commands: false, failures: true);
});

it('leaves the reserved keys and the ignore list alone', function () {
    $ignored = redisConnection();
    $ignored->shouldNotReceive('setEventDispatcher');

    $reserved = redisConnection();
    $reserved->shouldNotReceive('setEventDispatcher');

    $watched = redisConnection();
    $watched->shouldReceive('setEventDispatcher')->once();

    $opened = [];
    $this->app->instance('redis', redisManager([
        'options' => $reserved,
        'sessions' => $ignored,
        'cache' => $watched,
    ], $opened));

    (new RedisInstrumentation($this->app))->register($this->app->make('events'), ['sessions']);
});
