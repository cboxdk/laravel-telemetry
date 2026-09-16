<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Native;

use Cbox\Telemetry\Contracts\ManagesRequestState;
use Cbox\Telemetry\Contracts\NativeRuntime;
use Cbox\Telemetry\Support\Cast;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\Tracing\Span;
use Illuminate\Support\Str;

/**
 * Opens and closes native units of work, and owns the one rule the
 * extension will not enforce for you: units do not nest.
 *
 * A second `begin()` inside an open unit abandons the first one in the C
 * — silently, and with the first unit's samples. That is easy to trigger
 * from Laravel without meaning to: a sync job dispatched inside a request,
 * a scheduled task inside `schedule:run`, a job inside `queue:work`. So
 * the outermost unit wins here, and the inner site gets null.
 *
 * Which unit deserves to be the outermost one is not always the outermost
 * *call*: `queue:work` runs for hours and would swallow every job in it.
 * Those commands are listed in `telemetry.native.exclude_commands` and
 * open no unit at all, leaving the boundary to whatever runs inside them.
 */
final class NativeProfiler implements ManagesRequestState
{
    private ?NativeUnit $active = null;

    public function __construct(private readonly NativeRuntime $runtime) {}

    public function available(): bool
    {
        return $this->runtime->available();
    }

    public function runtime(): NativeRuntime
    {
        return $this->runtime;
    }

    /**
     * @param  'command'|'http'|'queue'|'schedule'  $unit
     */
    public function begin(string $unit, ?Span $span = null): ?NativeUnit
    {
        // Units do not nest, and the outermost one is the one that was
        // asked for first.
        if ($this->active !== null || ! $this->runtime->available()) {
            return null;
        }

        if (! Cast::bool(config('telemetry.native.enabled'), true)) {
            return null;
        }

        return FailSafe::guard(function () use ($unit, $span): ?NativeUnit {
            $profile = Cast::bool(config('telemetry.native.profile'), true)
                && Cast::bool(config('telemetry.instrument.profiling'), true)
                && ($span === null || $span->sampled);

            $context = [
                'unit' => $unit,
                'sampled' => $span === null || $span->sampled,
                'profile' => $profile,
            ];

            if ($span !== null) {
                // Carried for the crash record: a process that dies below the
                // level PHP can see still leaves behind which trace it died in.
                $context['trace_id'] = $span->traceId;
                $context['span_id'] = $span->spanId;
            }

            if (($period = Cast::int(config('telemetry.native.period_us'), 0)) > 0) {
                $context['period_us'] = $period;
            }

            if (($depth = Cast::int(config('telemetry.native.max_depth'), 0)) > 0) {
                $context['max_depth'] = $depth;
            }

            $handle = $this->runtime->begin($context);

            if ($handle === 0) {
                return null;
            }

            return $this->active = new NativeUnit(
                runtime: $this->runtime,
                handle: $handle,
                keepProfileAboveMs: Cast::float(config('telemetry.profiling.min_duration_ms'), 500.0),
                includeStacks: Cast::bool(config('telemetry.native.stacks'), false),
                topFunctions: Cast::int(config('telemetry.profiling.top_functions'), 20),
                maxStackNodes: Cast::int(config('telemetry.native.max_stack_nodes'), 2048),
                onFinish: function (): void {
                    $this->active = null;
                },
            );
        });
    }

    /**
     * A command that hosts its own units of work — a worker, a scheduler,
     * an application server — measures nothing useful as one unit.
     */
    public function hostsItsOwnUnits(string $command): bool
    {
        foreach (Cast::stringList(config('telemetry.native.exclude_commands', [])) as $pattern) {
            if (Str::is($pattern, $command)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Close whatever is open without reporting it — the Octane/NativePHP
     * reset between requests, where the request that opened it is gone.
     * Leaving it open would let one request's samples bleed into the next,
     * and would latch the one-unit-at-a-time rule shut for the rest of the
     * worker's life.
     */
    public function flushRequestState(): void
    {
        $active = $this->active;
        $this->active = null;

        $active?->discard();
    }
}
