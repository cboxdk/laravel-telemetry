<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\SpanStatus;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\Events\CommandFailed;

/**
 * Redis command spans (off by default — high volume) and Redis command
 * failures (on by default — rare and decisive). Each command becomes a
 * backdated detail span with the command name and the KEY argument only —
 * never values, they may hold session/user data.
 *
 * Failures are separated from commands because they answer a different
 * question. Per-command spans are a profiling tool you switch on when you
 * are looking at Redis; a command that never came back is how you find out
 * Redis is why everything else is broken, and you are not looking at Redis
 * when that happens. Laravel dispatches both events from the same switch,
 * so asking for failures does cost one event dispatch per command — no
 * span, no metric, one `in_array` and a return. That is the price of
 * knowing, and it is cheap next to an hour spent reading exceptions that
 * all have the same cause.
 *
 * The telemetry package's own connections (metric store, spool) are
 * ignored to prevent self-instrumentation feedback: telemetry writes
 * would otherwise generate spans that generate writes.
 */
final class RedisInstrumentation
{
    /**
     * Reserved keys under `database.redis` that configure the client rather
     * than naming a connection. Opening any of them as a connection throws
     * ("Redis connection [client] not configured"), so they are always
     * skipped when retro-fitting already-resolved connections.
     *
     * @var list<string>
     */
    private const RESERVED_KEYS = ['client', 'options', 'cluster'];

    /** @var list<string> */
    private array $ignoreConnections = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  list<string>  $ignoreConnections
     * @param  bool  $commands  span + counter per executed command
     * @param  bool  $failures  counter + errored span per failed command
     */
    public function register(Dispatcher $events, array $ignoreConnections = [], bool $commands = true, bool $failures = true): void
    {
        $this->ignoreConnections = $ignoreConnections;

        if (! $commands && ! $failures) {
            return;
        }

        FailSafe::guard(function () {
            $redis = $this->container->make('redis');

            // Future connections fire events…
            if (method_exists($redis, 'enableEvents')) {
                $redis->enableEvents();
            }

            // …and retro-fit the ones already open. `connections()`
            // returns what the manager has ALREADY created, so this
            // touches only live instances and opens nothing — the earlier
            // version read the config and called `connection()` on every
            // name, which is I/O during boot and is exactly what the
            // service provider is not allowed to do.
            //
            // It also runs for failures-only mode now. An application
            // that resolved its cache connection before we booted would
            // otherwise never report that connection dying, which is the
            // one thing failures-only mode exists for.
            $dispatcher = $this->container->make('events');

            if (! method_exists($redis, 'connections')) {
                return;
            }

            // `null`, not `[]`, until the first connection exists — and
            // at boot there usually is none. Iterating it raised a
            // warning that Laravel turns into an ErrorException, which
            // the guard above swallowed and then REPORTED: one error in
            // the log per boot, in every application, forever. A
            // swallowed failure is only harmless if it is also silent.
            foreach ($redis->connections() ?? [] as $name => $connection) {
                if (! is_object($connection)
                    || in_array((string) $name, self::RESERVED_KEYS, true)
                    || in_array((string) $name, $this->ignoreConnections, true)) {
                    continue;
                }

                FailSafe::guard(function () use ($connection, $dispatcher) {
                    if (method_exists($connection, 'setEventDispatcher')) {
                        $connection->setEventDispatcher($dispatcher);
                    }
                });
            }
        });

        if ($commands) {
            $events->listen(CommandExecuted::class, $this->executed(...));
        }

        if ($failures) {
            $events->listen(CommandFailed::class, $this->failed(...));
        }
    }

    private function executed(CommandExecuted $event): void
    {
        FailSafe::guard(function () use ($event) {
            if (in_array($event->connectionName, $this->ignoreConnections, true)) {
                return;
            }

            $telemetry = $this->container->make(TelemetryManager::class);

            $telemetry->counter('redis.commands', 'Redis commands executed')
                ->inc(1, ['command' => strtoupper($event->command), 'connection' => $event->connectionName]);

            $telemetry->tracer()->bumpStat('redis.command.count', 1);
            $telemetry->tracer()->bumpStat('redis.command.time_ms', $event->time);

            if ($telemetry->currentSpan()?->sampled !== true) {
                return;
            }

            $key = $event->parameters[0] ?? null;

            $telemetry->tracer()->recordSpan(
                'redis '.strtoupper($event->command),
                max(0.0, (float) $event->time),
                array_filter([
                    'db.system.name' => 'redis',
                    'db.operation.name' => strtoupper($event->command),
                    'laravel.db.connection' => $event->connectionName,
                    'db.redis.key' => is_string($key) ? $key : null,
                ], static fn ($value) => $value !== null),
                SpanKind::Client,
                detail: true,
            );
        });
    }

    /**
     * A command that did not come back.
     *
     * The one Redis signal that matters most in an incident, and the one
     * `CommandExecuted` never carries: a node that has gone away produces
     * failures, not slow successes. The span is marked errored so a trace
     * shows the cache as the thing that broke, and correlation upstream can
     * name it as a failing dependency.
     */
    private function failed(CommandFailed $event): void
    {
        FailSafe::guard(function () use ($event) {
            if (in_array($event->connectionName, $this->ignoreConnections, true)) {
                return;
            }

            $telemetry = $this->container->make(TelemetryManager::class);
            $command = strtoupper($event->command);

            // The same keys the span uses, and the exception's full class
            // name: two `NotFoundException`es from different namespaces
            // are different failures, and a basename merges them.
            $telemetry->counter('redis.commands.failed', 'Redis commands that raised')
                ->inc(1, [
                    'db.operation.name' => $command,
                    'laravel.db.connection' => $event->connectionName,
                    'error.type' => $event->exception::class,
                ]);

            $telemetry->tracer()->bumpStat('redis.command.failed', 1);

            if ($telemetry->currentSpan()?->sampled !== true) {
                return;
            }

            // Zero duration, deliberately. `CommandFailed` carries no
            // elapsed time, and the only way to measure one would be to
            // wrap every command — the high-volume thing this package is
            // careful not to do by default. The span marks WHEN the cache
            // stopped answering, not what the wait cost; a timeout will
            // therefore read as instantaneous, and the alternative was
            // making the failure invisible in the trace entirely.
            //
            // The status goes in as an argument: recordSpan ends the span,
            // which can flush it before a caller could set anything on it.
            $telemetry->tracer()->recordSpan(
                'redis '.$command,
                0.0,
                [
                    'db.system.name' => 'redis',
                    'db.operation.name' => $command,
                    'laravel.db.connection' => $event->connectionName,
                    'error.type' => $event->exception::class,
                ],
                SpanKind::Client,
                detail: true,
                status: SpanStatus::Error,
                statusDescription: $event->exception->getMessage(),
            );
        });
    }
}
