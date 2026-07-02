<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\SpanKind;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Redis\Events\CommandExecuted;

/**
 * Redis command spans (off by default — high volume). Each command
 * becomes a backdated detail span with the command name and the KEY
 * argument only — never values, they may hold session/user data.
 *
 * The telemetry package's own connections (metric store, spool) are
 * ignored to prevent self-instrumentation feedback: telemetry writes
 * would otherwise generate spans that generate writes.
 */
final class RedisInstrumentation
{
    /** @var list<string> */
    private array $ignoreConnections = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  list<string>  $ignoreConnections
     */
    public function register(Dispatcher $events, array $ignoreConnections = []): void
    {
        $this->ignoreConnections = $ignoreConnections;

        FailSafe::guard(function () {
            $redis = $this->container->make('redis');

            // Future connections fire events…
            if (method_exists($redis, 'enableEvents')) {
                $redis->enableEvents();
            }

            // …and retro-fit any already-resolved connection (the metric
            // store may have opened one before us). Setting the dispatcher
            // on the cached instance is what actually enables its events.
            $connections = (array) $this->container->make('config')->get('database.redis', []);
            $dispatcher = $this->container->make('events');

            foreach (array_keys($connections) as $name) {
                if ($name === 'options' || in_array((string) $name, $this->ignoreConnections, true)) {
                    continue;
                }

                FailSafe::guard(function () use ($redis, $name, $dispatcher) {
                    $connection = $redis->connection((string) $name);

                    if (method_exists($connection, 'setEventDispatcher')) {
                        $connection->setEventDispatcher($dispatcher);
                    }
                });
            }
        });

        $events->listen(CommandExecuted::class, $this->executed(...));
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
                    'db.connection' => $event->connectionName,
                    'db.redis.key' => is_string($key) ? $key : null,
                ], static fn ($value) => $value !== null),
                SpanKind::Client,
                detail: true,
            );
        });
    }
}
