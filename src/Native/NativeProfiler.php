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

    /**
     * The process that opened `$active`. A fork copies the PHP object but
     * not the ownership: the child's copy refers to a unit the parent is
     * going to finish, so the child must not hold it — and must not finish
     * it either, since the extension's own fork detection has already made
     * that handle mean something different there.
     */
    private ?int $activePid = null;

    /** Whether a sampler exists at all in this process. Cannot change. */
    private ?bool $profilerEnabled = null;

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
     * Whether the NATIVE profiler is the one that will sample this process.
     *
     * Not the same question as "did this call get a unit". A unit opens
     * even when profiling is unavailable — the handle is real and
     * `finish()` then reports `profiling => false` — so treating a handle
     * as proof of profiling would silence an installed ext-excimer on
     * every host where the extension is loaded but its sampler is not
     * running (`native.profile=false`, `cbox_telemetry.profiler.enabled=0`,
     * or macOS, which has no per-thread CPU timer).
     *
     * Call sites use this, and only this, to decide whether to start
     * excimer — including when they were refused a unit for nesting, which
     * means an outer native unit is already sampling this process.
     */
    public function profiles(): bool
    {
        if (! $this->runtime->available()
            || ! Cast::bool(config('telemetry.native.enabled'), true)
            || ! Cast::bool(config('telemetry.native.profile'), true)
            || ! Cast::bool(config('telemetry.instrument.profiling'), true)
        ) {
            return false;
        }

        // Decided at module startup (timer backend, INI, platform), so it is
        // read once per process rather than once per unit.
        //
        // BOTH flags: with `cbox_telemetry.enabled=0` the C returns from
        // MINIT before it touches the profiler, so `profiler_enabled` keeps
        // its INI default of true while nothing whatsoever is running. Read
        // alone it would report a sampler that does not exist and silence
        // excimer on a host that had deliberately switched the extension off.
        return $this->profilerEnabled ??= FailSafe::guard(function (): bool {
            $status = $this->runtime->status();

            return Cast::bool($status['enabled'] ?? null, false)
                && Cast::bool($status['profiler_enabled'] ?? null, false);
        }) ?? false;
    }

    /**
     * @param  'command'|'http'|'queue'|'schedule'  $unit
     */
    public function begin(string $unit, ?Span $span = null): ?NativeUnit
    {
        if (! $this->runtime->available() || ! Cast::bool(config('telemetry.native.enabled'), true)) {
            return null;
        }

        // Units do not nest, and the outermost one is the one that was
        // asked for first — unless "first" happened in another process.
        if ($this->activeInThisProcess()) {
            return null;
        }

        return FailSafe::guard(function () use ($unit, $span): ?NativeUnit {
            $profile = $this->profiles() && ($span === null || $span->sampled);

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

            $elapsedBefore = $this->elapsedBeforeAdoption();
            $handle = $this->runtime->begin($context);

            if ($handle === 0) {
                return null;
            }

            $this->activePid = getmypid() ?: null;

            return $this->active = new NativeUnit(
                runtime: $this->runtime,
                handle: $handle,
                elapsedBeforeAdoptionMs: $elapsedBefore,
                keepProfileAboveMs: Cast::float(config('telemetry.profiling.min_duration_ms'), 500.0),
                includeStacks: Cast::bool(config('telemetry.native.stacks'), false),
                topFunctions: Cast::int(config('telemetry.profiling.top_functions'), 20),
                maxStackNodes: Cast::int(config('telemetry.native.max_stack_nodes'), 2048),
                // Identity-checked: a unit object can outlive its hold on
                // the latch — a fork leaves copies in other owners' hands,
                // and an Octane reset drops the profiler's reference while
                // something else still holds the object. Either way the
                // stale unit's discard() must not release a latch that now
                // belongs to a live one.
                onFinish: function (NativeUnit $finished): void {
                    if ($this->active === $finished) {
                        $this->active = null;
                        $this->activePid = null;
                    }
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
        $active = $this->activeInThisProcess() ? $this->active : null;

        $this->active = null;
        $this->activePid = null;

        $active?->discard();
    }

    private function activeInThisProcess(): bool
    {
        if ($this->active === null) {
            return false;
        }

        if ($this->activePid !== null && $this->activePid !== getmypid()) {
            // Inherited across a fork. Drop it rather than finishing it: the
            // handle belongs to the parent's unit, and the extension has
            // already reset its own state for this process.
            $this->active = null;
            $this->activePid = null;

            return false;
        }

        return true;
    }

    /**
     * How long the unit this `begin()` is about to ADOPT has already been
     * running.
     *
     * With `cbox_telemetry.auto=1` a unit opens at RINIT, before any PHP
     * runs, and `begin()` adopts it — which is the whole point, because
     * the samples worth having are the ones from autoloading, providers
     * and config. Timing that unit from the adoption would hand the tail
     * threshold the wrong number and throw away exactly those profiles: an
     * 800 ms bootstrap followed by 10 ms of routing reads as a 10 ms unit.
     */
    private function elapsedBeforeAdoption(): float
    {
        $status = $this->runtime->status();

        // Nothing to adopt: this begin() opens its own unit, which starts now.
        if (Cast::int($status['unit_handle'] ?? null) === 0 || ! Cast::bool($status['unit_automatic'] ?? null, false)) {
            return 0.0;
        }

        // The SAPI's own request start, which is per REQUEST under FPM and
        // under Octane — LARAVEL_START is per process there, and would make
        // the first adopted unit look as old as the worker.
        $startedAt = Cast::float($_SERVER['REQUEST_TIME_FLOAT'] ?? null)
            ?: (defined('LARAVEL_START') ? Cast::float(constant('LARAVEL_START')) : 0.0);

        if ($startedAt <= 0) {
            return 0.0;
        }

        $elapsed = microtime(true) * 1000 - $startedAt * 1000;

        // Bounded by the extension's OWN limit on how long an automatic unit
        // may sample. A fixed minute was this package inventing a second,
        // stricter deadline: on a host configured for a two-minute cap, a
        // 61-second bootstrap — exactly the case worth profiling — read as
        // zero and lost its profile to the tail threshold.
        $cap = Cast::float(Cast::stringKeyedArray($status['limits'] ?? null)['auto_max_ms'] ?? null, 60_000.0);

        return $elapsed > 0 && $elapsed <= max(1_000.0, $cap) ? $elapsed : 0.0;
    }
}
