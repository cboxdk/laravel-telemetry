<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Instrumentation\InstrumentedConnectionFactory;
use Cbox\Telemetry\Instrumentation\InstrumentedRedisManager;
use Cbox\Telemetry\Instrumentation\TimedRedisConnector;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\SpanStatus;
use Illuminate\Contracts\Redis\Connector;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);
});

function connectSpans(CollectingExporter $collector, string $name): array
{
    return collect($collector->batches())
        ->flatMap(fn ($batch) => $batch->spans)
        ->filter(fn ($span) => $span->name === $name)
        ->values()
        ->all();
}

it('spans the handshake, not the query', function () {
    config()->set('database.connections.timed', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    Telemetry::span('root', function () {
        DB::connection('timed')->select('select 1');
    });
    Telemetry::flush();

    $spans = connectSpans($this->collector, 'db.connect');

    expect($spans)->toHaveCount(1);
    expect($spans[0]->kind)->toBe(SpanKind::Client);
    expect($spans[0]->attributes()['db.system.name'])->toBe('sqlite');
    expect($spans[0]->attributes()['laravel.db.connection'])->toBe('timed');
});

it('opens the connection once however many queries run', function () {
    // The point of timing the resolver rather than the query event: PDO is
    // built lazily and then kept, so a busy request pays for one handshake.
    config()->set('database.connections.once', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    Telemetry::span('root', function () {
        DB::connection('once')->select('select 1');
        DB::connection('once')->select('select 2');
        DB::connection('once')->select('select 3');
    });
    Telemetry::flush();

    expect(connectSpans($this->collector, 'db.connect'))->toHaveCount(1);
});

it('records a failed connect and lets the exception through', function () {
    config()->set('database.connections.broken', [
        'driver' => 'sqlite',
        'database' => '/nonexistent-directory-for-telemetry/db.sqlite',
        'prefix' => '',
    ]);

    $threw = false;

    Telemetry::span('root', function () use (&$threw) {
        try {
            DB::connection('broken')->select('select 1');
        } catch (Throwable) {
            $threw = true;
        }
    });

    expect($threw)->toBeTrue();
    Telemetry::flush();

    $spans = connectSpans($this->collector, 'db.connect');

    expect($spans)->toHaveCount(1);
    expect($spans[0]->status())->toBe(SpanStatus::Error);
    expect($spans[0]->attributes())->toHaveKey('error.type');
});

it('measures the connect time as its own duration', function () {
    // A connect span whose duration is the whole request would be worse than
    // none: it would read as the database hanging. Guard the arithmetic.
    config()->set('database.connections.duration', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    Telemetry::span('root', function () {
        DB::connection('duration')->select('select 1');
        usleep(50_000);
    });
    Telemetry::flush();

    $spans = connectSpans($this->collector, 'db.connect');

    expect($spans[0]->durationMs())->toBeLessThan(50.0);
});

it('times a connect through the connector wrapper', function () {
    $connector = Mockery::mock(Connector::class);
    $connector->shouldReceive('connect')->once()->andReturn(Mockery::mock(RedisConnection::class));

    $timed = new TimedRedisConnector($connector, app(), 'cache', []);

    Telemetry::span('root', fn () => $timed->connect(['host' => '10.0.0.5', 'port' => 6379], []));
    Telemetry::flush();

    $spans = connectSpans($this->collector, 'redis.connect');

    expect($spans)->toHaveCount(1);
    expect($spans[0]->attributes()['server.address'])->toBe('10.0.0.5');
    expect($spans[0]->attributes()['server.port'])->toBe(6379);
    expect($spans[0]->attributes()['laravel.db.connection'])->toBe('cache');
});

it('leaves the telemetry store and spool connections untimed', function () {
    // Timing the spool's own connect would record a span about opening the
    // spool, which is then written INTO the spool. This is the assertion
    // that the skip actually skips — not merely that a list was handed over.
    $connector = Mockery::mock(Connector::class);
    $connector->shouldReceive('connect')->once()->andReturn(Mockery::mock(RedisConnection::class));

    $timed = new TimedRedisConnector($connector, app(), 'spool', ['spool', 'metrics']);

    Telemetry::span('root', fn () => $timed->connect(['host' => '127.0.0.1', 'port' => 6379], []));
    Telemetry::flush();

    expect(connectSpans($this->collector, 'redis.connect'))->toBeEmpty();
});

it('decorates both managers', function () {
    expect(app('db.factory'))->toBeInstanceOf(InstrumentedConnectionFactory::class);
    expect(app('redis'))->toBeInstanceOf(InstrumentedRedisManager::class);
});
