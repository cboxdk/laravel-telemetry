<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanStatus;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Events\CommandFailed;
use Illuminate\Support\Facades\Event;

/**
 * A Redis node that has gone away produces failures, not slow successes,
 * and `CommandExecuted` never fires for them. Without this the cache could
 * die and the only trace of it would be the exceptions it caused
 * elsewhere.
 */
function redisFailure(string $command, Throwable $e, string $connection = 'cache'): CommandFailed
{
    $mock = Mockery::mock(Connection::class);
    $mock->shouldReceive('getName')->andReturn($connection);

    return new CommandFailed($command, ['sessions:9'], $e, $mock);
}

it('counts a failed redis command', function (): void {
    Telemetry::fake();

    Event::dispatch(redisFailure('get', new RuntimeException('Connection refused')));

    Telemetry::assertCounterIncremented('redis.commands.failed', [
        'db.operation.name' => 'GET',
        'laravel.db.connection' => 'cache',
        'error.type' => 'RuntimeException',
    ]);
});

it('marks the span as the thing that broke', function (): void {
    Telemetry::fake();

    $root = Telemetry::tracer()->startSpan('checkout');

    Event::dispatch(redisFailure('get', new RuntimeException('Connection refused')));

    $root->end();

    Telemetry::assertSpanRecorded('redis GET', fn (Span $span): bool => $span->attributes()['db.system.name'] === 'redis'
        && $span->attributes()['error.type'] === 'RuntimeException'
        && $span->status() === SpanStatus::Error);
});

it('leaves the telemetry package own connections alone', function (): void {
    Telemetry::fake();

    Event::dispatch(redisFailure('get', new RuntimeException('down'), 'default'));

    Telemetry::assertCounterNotIncremented('redis.commands.failed');
});
