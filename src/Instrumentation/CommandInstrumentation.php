<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Contracts\ManagesRequestState;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanStatus;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Wraps Artisan commands in spans (opt-in via config, since schedulers
 * can be chatty).
 */
final class CommandInstrumentation implements ManagesRequestState
{
    /** @var list<Span> */
    private array $stack = [];

    public function __construct(private readonly Container $container) {}

    /**
     * Resolved per event so Telemetry::fake() swaps take effect.
     */
    private function telemetry(): TelemetryManager
    {
        return $this->container->make(TelemetryManager::class);
    }

    public function register(Dispatcher $events): void
    {
        $events->listen(CommandStarting::class, $this->commandStarting(...));
        $events->listen(CommandFinished::class, $this->commandFinished(...));
    }

    private function commandStarting(CommandStarting $event): void
    {
        FailSafe::guard(function () use ($event) {
            $this->stack[] = $this->telemetry()->tracer()->startSpan(
                'artisan '.($event->command ?? 'unknown'),
                attributes: ['laravel.command' => $event->command ?? 'unknown'],
            );
        });
    }

    private function commandFinished(CommandFinished $event): void
    {
        $span = array_pop($this->stack);

        if ($span === null) {
            return;
        }

        FailSafe::guard(function () use ($span, $event) {
            $span->setAttribute('laravel.command.exit_code', $event->exitCode);
            $span->setStatus($event->exitCode === 0 ? SpanStatus::Ok : SpanStatus::Error);
            $span->end();

            $labels = ['command' => $event->command ?? 'unknown'];

            $this->telemetry()
                ->histogram('command.duration', description: 'Artisan command duration', unit: 'ms')
                ->record($span->durationMs(), $labels);

            $this->telemetry()
                ->counter($event->exitCode === 0 ? 'commands.completed' : 'commands.failed', 'Artisan command runs by outcome')
                ->inc(1, $labels);
        });

        if ($this->stack === []) {
            $this->telemetry()->flush();
            $this->telemetry()->resetContext();
        }
    }

    public function flushRequestState(): void
    {
        $this->stack = [];
    }
}
