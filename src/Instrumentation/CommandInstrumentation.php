<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Contracts\ManagesRequestState;
use Cbox\Telemetry\Native\NativeProfiler;
use Cbox\Telemetry\Native\NativeReporter;
use Cbox\Telemetry\Native\NativeUnit;
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

    /** @var array<int, NativeUnit> keyed by span object id */
    private array $units = [];

    public function __construct(private readonly Container $container) {}

    /**
     * Resolved per event so Telemetry::fake() swaps take effect.
     */
    private function telemetry(): TelemetryManager
    {
        return $this->container->make(TelemetryManager::class);
    }

    private function native(): NativeProfiler
    {
        return $this->container->make(NativeProfiler::class);
    }

    public function register(Dispatcher $events): void
    {
        $events->listen(CommandStarting::class, $this->commandStarting(...));
        $events->listen(CommandFinished::class, $this->commandFinished(...));
    }

    private function commandStarting(CommandStarting $event): void
    {
        // Never trace the package's own plumbing — telemetry:flush would
        // spool a span for itself on every run (self-perpetuating), and
        // telemetry:monitor is a long-lived daemon.
        if (str_starts_with($event->command ?? '', 'telemetry:')) {
            return;
        }

        FailSafe::guard(function () use ($event) {
            $command = $event->command ?? 'unknown';

            $span = $this->telemetry()->tracer()->startSpan(
                'artisan '.$command,
                attributes: ['laravel.command' => $command],
            );

            $this->stack[] = $span;

            // A worker or an application server is not a unit of work — it
            // is the thing units of work happen inside. Those commands open
            // nothing, so the jobs and tasks within them can.
            if (! $this->native()->hostsItsOwnUnits($command)) {
                $unit = $this->native()->begin('command', $span);

                if ($unit !== null) {
                    $this->units[spl_object_id($span)] = $unit;
                }
            }
        });
    }

    private function commandFinished(CommandFinished $event): void
    {
        $span = array_pop($this->stack);

        if ($span === null) {
            return;
        }

        // Taken out here rather than inside the guard below: a throw the
        // guard swallows must not leave the unit open, because the one-unit-
        // at-a-time rule would then refuse every later command in this
        // process (`schedule:run` runs many).
        $unit = $this->units[spl_object_id($span)] ?? null;
        unset($this->units[spl_object_id($span)]);

        FailSafe::guard(function () use ($span, $event, $unit) {
            $span->setAttribute('laravel.command.exit_code', $event->exitCode);
            $span->setStatus($event->exitCode === 0 ? SpanStatus::Ok : SpanStatus::Error);

            // Before end() — see the ordering note in TraceRequest.
            if ($unit !== null) {
                $result = $unit->finish(
                    $this->telemetry()->tracer()->currentlySampled()
                        || $span->status() === SpanStatus::Error,
                );

                if ($result !== null) {
                    NativeReporter::report($this->telemetry(), $result, $span, [
                        'laravel.command' => $event->command ?? 'unknown',
                    ]);
                }
            }

            $span->end();

            $labels = ['command' => $event->command ?? 'unknown'];

            $this->telemetry()
                ->histogram('command.duration', description: 'Artisan command duration', unit: 'ms')
                ->record($span->durationMs(), $labels);

            $this->telemetry()
                ->counter($event->exitCode === 0 ? 'commands.completed' : 'commands.failed', 'Artisan command runs by outcome')
                ->inc(1, $labels);
        });

        $unit?->discard();

        if ($this->stack === []) {
            $this->telemetry()->flush();
            $this->telemetry()->resetContext();
        }
    }

    public function flushRequestState(): void
    {
        // Dropping the map is not enough on its own: the spans stay on the
        // tracer's context stack, and the shutdown path ends everything still
        // open as an error that lasted until the process died. Discarded
        // properly instead — a missing span is the lesser evil, a span with the
        // wrong duration and status is a lie that reads as data.

        foreach ($this->stack as $span) {
            FailSafe::guard(fn () => $this->telemetry()->tracer()->discardSpan($span));
        }

        foreach ($this->units as $unit) {
            FailSafe::guard(static fn () => $unit->discard());
        }

        $this->stack = [];
        $this->units = [];
    }
}
