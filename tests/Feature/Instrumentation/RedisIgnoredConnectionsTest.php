<?php

declare(strict_types=1);

use Cbox\Telemetry\TelemetryServiceProvider;
use Illuminate\Contracts\Config\Repository;

/**
 * Which Redis connections the instrumentation must leave alone.
 *
 * The list is fixed when the provider registers, so it is asserted on the
 * method that builds it rather than through a booted app per case.
 */
function ignoredRedisConnections(): array
{
    $method = new ReflectionMethod(TelemetryServiceProvider::class, 'ignoredRedisConnections');

    return $method->invoke(new TelemetryServiceProvider(app()), app(Repository::class));
}

beforeEach(function () {
    config()->set('telemetry.store', 'array');
    config()->set('telemetry.stores.redis.connection', 'metrics');
    config()->set('telemetry.otlp.spool.enabled', false);
    config()->set('telemetry.otlp.spool.driver', 'redis');
    config()->set('telemetry.otlp.spool.connection', 'spool');
    config()->set('telemetry.instrument.redis_ignore_connections', null);
});

it('ignores nothing when telemetry keeps neither its metrics nor its spool in Redis', function () {
    // Both settings default to `default` — the connection most apps cache
    // and rate-limit on. Ignoring them while unused hid that traffic.
    expect(ignoredRedisConnections())->toBe([]);
});

it('ignores the metric store connection when the store is redis', function () {
    config()->set('telemetry.store', 'redis');

    expect(ignoredRedisConnections())->toBe(['metrics']);
});

it('ignores the spool connection when a redis spool is on', function () {
    config()->set('telemetry.otlp.spool.enabled', true);

    expect(ignoredRedisConnections())->toBe(['spool']);
});

it('does not ignore the spool connection for a sqlite spool', function () {
    config()->set('telemetry.otlp.spool.enabled', true);
    config()->set('telemetry.otlp.spool.driver', 'sqlite');

    expect(ignoredRedisConnections())->toBe([]);
});

it('unions an explicit ignore list with the package connections, once each', function () {
    config()->set('telemetry.store', 'redis');
    config()->set('telemetry.otlp.spool.enabled', true);
    config()->set('telemetry.stores.redis.connection', 'default');
    config()->set('telemetry.otlp.spool.connection', 'default');
    config()->set('telemetry.instrument.redis_ignore_connections', ['sessions', 'default']);

    expect(ignoredRedisConnections())->toBe(['default', 'sessions']);
});
