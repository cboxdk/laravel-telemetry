<?php

declare(strict_types=1);

use Cbox\Telemetry\Instrumentation\RedisInstrumentation;
use Illuminate\Redis\RedisManager;

it('skips the reserved database.redis keys, not just options, when retro-fitting connections', function () {
    // A realistic phpredis config block: `client`, `options` and `cluster`
    // are configuration, not connections. Only `default` names a connection.
    config()->set('database.redis', [
        'client' => 'phpredis',
        'options' => ['prefix' => 'x_'],
        'cluster' => ['options' => []],
        'default' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0],
    ]);

    $opened = [];

    $redis = Mockery::mock(RedisManager::class);
    $redis->shouldReceive('enableEvents')->andReturnNull();
    $redis->shouldReceive('connection')->andReturnUsing(function ($name) use (&$opened) {
        $opened[] = $name;

        return Mockery::mock();
    });

    $this->app->instance('redis', $redis);

    (new RedisInstrumentation($this->app))->register($this->app->make('events'));

    expect($opened)->toContain('default')
        ->and($opened)->not->toContain('client')
        ->and($opened)->not->toContain('options')
        ->and($opened)->not->toContain('cluster');
});

it('also honours an operator-supplied ignore list alongside the reserved keys', function () {
    config()->set('database.redis', [
        'client' => 'phpredis',
        'default' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0],
        'sessions' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 1],
    ]);

    $opened = [];

    $redis = Mockery::mock(RedisManager::class);
    $redis->shouldReceive('enableEvents')->andReturnNull();
    $redis->shouldReceive('connection')->andReturnUsing(function ($name) use (&$opened) {
        $opened[] = $name;

        return Mockery::mock();
    });

    $this->app->instance('redis', $redis);

    (new RedisInstrumentation($this->app))->register($this->app->make('events'), ['sessions']);

    expect($opened)->toContain('default')
        ->and($opened)->not->toContain('sessions')
        ->and($opened)->not->toContain('client');
});
