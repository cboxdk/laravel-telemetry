<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Instrumentation\ConnectionTiming;
use Cbox\Telemetry\Instrumentation\TimedPredisConnectionFactory;
use Cbox\Telemetry\Instrumentation\TimedPredisConnector;
use Cbox\Telemetry\Instrumentation\TimedPredisStreamConnection;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\SpanStatus;
use Illuminate\Contracts\Redis\Connector;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\Redis;
use Predis\Connection\Factory;
use Predis\Connection\StreamConnection;

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);

    config()->set('database.redis.client', 'predis');
    app()->forgetInstance('redis');
});

/**
 * The manager reads its config once, and the facade keeps the instance it
 * was first handed — forget both after changing a connection.
 */
function freshRedis(): void
{
    app()->forgetInstance('redis');
    Redis::clearResolvedInstance('redis');
}

function predisConnectSpans(CollectingExporter $collector): array
{
    return collect($collector->batches())
        ->flatMap(fn ($batch) => $batch->spans)
        ->filter(fn ($span) => $span->name === 'redis.connect')
        ->values()
        ->all();
}

it('opens nothing, and records nothing, until the first command', function () {
    // The reason the connector is not timed: building the client is not a
    // connect. A span here would be a few microseconds that never touched
    // the network.
    config()->set('database.redis.lazy', ['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.5]);

    Telemetry::span('root', fn () => Redis::connection('lazy'));
    Telemetry::flush();

    expect(predisConnectSpans($this->collector))->toBeEmpty();
});

it('records a refused connect on the first command and lets the exception through', function () {
    // Port 1 refuses at once, so this needs no Redis and no wait — and a
    // refused handshake is the case the span exists for.
    config()->set('database.redis.refused', ['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.5]);

    $threw = false;

    Telemetry::span('root', function () use (&$threw) {
        try {
            Redis::connection('refused')->get('anything');
        } catch (Throwable) {
            $threw = true;
        }
    });
    Telemetry::flush();

    expect($threw)->toBeTrue();

    $spans = predisConnectSpans($this->collector);

    expect($spans)->toHaveCount(1);
    expect($spans[0]->status())->toBe(SpanStatus::Error);
    expect($spans[0]->attributes())->toMatchArray([
        'db.system.name' => 'redis',
        'laravel.db.connection' => 'refused',
        'server.address' => '127.0.0.1',
        'server.port' => 1,
    ]);
    expect($spans[0]->attributes())->toHaveKey('error.type');
});

it('leaves a connection that brings its own connection factory alone', function () {
    // `connections` set by the host is the host's factory: replacing it
    // would change which classes predis connects with.
    config()->set('database.redis.own', [
        'host' => '127.0.0.1',
        'port' => 1,
        'timeout' => 0.5,
        'options' => ['connections' => ['tcp' => StreamConnection::class]],
    ]);

    Telemetry::span('root', function () {
        try {
            Redis::connection('own')->get('anything');
        } catch (Throwable) {
        }
    });
    Telemetry::flush();

    expect(predisConnectSpans($this->collector))->toBeEmpty();
});

it('leaves the telemetry store and spool connections untimed', function () {
    // Timing the spool's own connect would record a span about opening the
    // spool, which is then written INTO the spool. Assert the skip actually
    // skips: the client is built without a timed factory.
    $inner = Mockery::mock(Connector::class);
    $inner->shouldReceive('connect')
        ->once()
        ->withArgs(fn (array $config, array $options): bool => ! array_key_exists('connections', $options))
        ->andReturn(Mockery::mock(RedisConnection::class));

    (new TimedPredisConnector($inner, app(), 'spool', ['spool', 'metrics']))->connect(['host' => '127.0.0.1'], []);
});

it('hands every other connection a timed factory', function () {
    $inner = Mockery::mock(Connector::class);
    $inner->shouldReceive('connect')
        ->once()
        ->withArgs(fn (array $config, array $options): bool => ($options['connections'] ?? null) instanceof Closure)
        ->andReturn(Mockery::mock(RedisConnection::class));

    (new TimedPredisConnector($inner, app(), 'cache', ['spool', 'metrics']))->connect(['host' => '127.0.0.1'], []);
});

it('swaps only the schemes still mapped to predis stream connections', function () {
    // Every node predis opens — a server, a replica, a sentinel and the
    // master it names — is built by the client's factory, so this is what
    // decides whether a connect is timed. A scheme the host redefined is
    // left as it is: we cannot know when it connects.
    $factory = new TimedPredisConnectionFactory(new ConnectionTiming(app()), 'cache');
    $factory->define('unix', fn ($parameters) => new StreamConnection($parameters));

    expect($factory->create('tcp://127.0.0.1:6379'))->toBeInstanceOf(TimedPredisStreamConnection::class);
    expect($factory->create('tls://127.0.0.1:6379'))->toBeInstanceOf(TimedPredisStreamConnection::class);
    expect($factory->create('unix:///tmp/redis.sock'))->not->toBeInstanceOf(TimedPredisStreamConnection::class);
    expect($factory)->toBeInstanceOf(Factory::class);
});

describe('against a real Redis', function () {
    beforeEach(function () {
        config()->set('database.redis.timed', ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0, 'timeout' => 1.0]);

        try {
            Redis::connection('timed')->ping();
        } catch (Throwable) {
            $this->markTestSkipped('No Redis server on 127.0.0.1:6379.');
        }

        // The probe above connected; start every test from a fresh client
        // and a collector that never saw the probe.
        freshRedis();
        Telemetry::flush();
        $this->collector = new CollectingExporter;
        Telemetry::addExporter($this->collector);
    });

    it('records one connect however many commands run', function () {
        Telemetry::span('root', function () {
            $connection = Redis::connection('timed');
            $connection->ping();
            $connection->ping();
            $connection->ping();
        });
        Telemetry::flush();

        $spans = predisConnectSpans($this->collector);

        expect($spans)->toHaveCount(1);
        expect($spans[0]->status())->not->toBe(SpanStatus::Error);
        expect($spans[0]->attributes())->toMatchArray([
            'laravel.db.connection' => 'timed',
            'server.address' => '127.0.0.1',
            'server.port' => 6379,
        ]);
    });

    it('keeps the parameters option, which carries auth and database to every node', function () {
        // predis uses a factory instance exactly as given, so a ready-made
        // one would drop `parameters` — the password a Sentinel setup sends
        // to the master. Prove the option still reaches the connection.
        $key = 'telemetry_test_'.bin2hex(random_bytes(4));

        config()->set('database.redis.params', [
            'host' => '127.0.0.1',
            'port' => 6379,
            'timeout' => 1.0,
            'options' => ['parameters' => ['database' => 7]],
        ]);
        config()->set('database.redis.db7', ['host' => '127.0.0.1', 'port' => 6379, 'database' => 7, 'timeout' => 1.0]);
        freshRedis();

        try {
            Redis::connection('params')->set($key, 'x');

            expect(Redis::connection('db7')->get($key))->toBe('x');
        } finally {
            Redis::connection('db7')->del($key);
        }
    });
})->group('redis');
