<?php

declare(strict_types=1);

use Cbox\Telemetry\Support\ServiceInstance;
use Illuminate\Config\Repository;

/**
 * @param  array<string, mixed>  $redis  the telemetry connection's settings
 */
function instanceConfig(string $store = 'redis', array $redis = [], array $extra = []): Repository
{
    $config = new Repository([
        'telemetry' => ['store' => $store, 'stores' => ['redis' => ['connection' => 'telemetry', 'prefix' => 'telemetry']]],
        'database' => ['redis' => ['telemetry' => $redis]],
    ]);

    foreach ($extra as $key => $value) {
        $config->set($key, $value);
    }

    return $config;
}

it('names the host for a store that lives on it', function (string $store, array $redis) {
    expect(ServiceInstance::id(instanceConfig($store, $redis), 'web-1'))->toBe('web-1');
})->with([
    'apcu' => ['apcu', []],
    'sqlite' => ['sqlite', []],
    'array' => ['array', []],
    'redis on loopback' => ['redis', ['host' => '127.0.0.1', 'port' => '6379']],
    'redis on localhost' => ['redis', ['host' => 'localhost']],
    'redis on ipv6 loopback' => ['redis', ['host' => '[::1]']],
    'redis on a socket' => ['redis', ['scheme' => 'unix', 'path' => '/run/redis.sock']],
    'redis url on loopback' => ['redis', ['url' => 'redis://127.0.0.1:6379']],
    'redis url on a socket' => ['redis', ['url' => 'unix:///run/redis.sock']],
]);

it('gives every host of a shared store the same id', function () {
    // One store, one total: whichever host exports it, it is one series.
    $config = instanceConfig('redis', ['host' => 'redis.internal', 'port' => '6379', 'database' => '0']);

    $id = ServiceInstance::id($config, 'web-1');

    expect($id)->toMatch('/^redis-[0-9a-f]{12}$/')
        ->and(ServiceInstance::id($config, 'web-2'))->toBe($id);
});

it('reads the same store the same way, whether as a url or as fields', function () {
    $fields = instanceConfig('redis', ['host' => 'redis.internal', 'port' => '6379', 'database' => '0']);
    $url = instanceConfig('redis', ['url' => 'redis://redis.internal:6379/0']);

    expect(ServiceInstance::id($url, 'web-1'))->toBe(ServiceInstance::id($fields, 'web-2'));
});

it('keeps credentials out of the fingerprint', function () {
    // Rotating a password must not start a new series, and the id must
    // not be derived from a secret.
    $plain = instanceConfig('redis', ['url' => 'tls://redis.internal:6380/1']);
    $secret = instanceConfig('redis', ['url' => 'tls://user:hunter2@redis.internal:6380/1', 'password' => 'hunter2', 'username' => 'user']);

    expect(ServiceInstance::id($secret, 'web-1'))->toBe(ServiceInstance::id($plain, 'web-1'));
});

it('tells different stores apart', function () {
    $base = ['host' => 'redis.internal', 'port' => '6379', 'database' => '0'];

    $ids = [
        ServiceInstance::id(instanceConfig('redis', $base), 'web'),
        ServiceInstance::id(instanceConfig('redis', [...$base, 'database' => '1']), 'web'),
        ServiceInstance::id(instanceConfig('redis', [...$base, 'host' => 'other.internal']), 'web'),
        ServiceInstance::id(instanceConfig('redis', $base, ['telemetry.stores.redis.prefix' => 'shop']), 'web'),
        ServiceInstance::id(instanceConfig('redis', $base, ['database.redis.options.prefix' => 'shop_']), 'web'),
    ];

    expect(array_unique($ids))->toHaveCount(5);
});

it('fingerprints a cluster by its nodes, in any order', function () {
    $config = fn (array $nodes): Repository => new Repository([
        'telemetry' => ['store' => 'redis', 'stores' => ['redis' => ['connection' => 'metrics']]],
        'database' => ['redis' => ['clusters' => ['metrics' => $nodes]]],
    ]);

    $a = ['host' => '10.0.0.1', 'port' => '6379'];
    $b = ['host' => '10.0.0.2', 'port' => '6379'];

    $id = ServiceInstance::id($config([$a, $b]), 'web-1');

    expect($id)->toMatch('/^redis-[0-9a-f]{12}$/')
        ->and(ServiceInstance::id($config([$b, $a]), 'web-2'))->toBe($id);
});

it('still gives a shared id when the connection says nothing', function () {
    $config = instanceConfig('redis', []);

    expect(ServiceInstance::id($config, 'web-1'))
        ->toMatch('/^redis-[0-9a-f]{12}$/')
        ->toBe(ServiceInstance::id($config, 'web-2'));
});
