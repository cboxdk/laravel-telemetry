<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Native;

use Cbox\Telemetry\Contracts\NativeRuntime;
use Closure;

/**
 * One open unit of work — a request, a job, a command, a scheduled task.
 *
 * It times itself rather than asking the span, because the decision it has
 * to make ("was this slow enough to keep the profile?") happens while the
 * span is still open and has no duration yet. Finishing after the span has
 * ended would answer the question correctly and attach the answer to
 * nothing.
 *
 * Starting the profiler and throwing the result away is the cheap path:
 * the sampler's cost is roughly constant, and a unit that turns out to be
 * fast resets the native state without ever allocating a PHP array.
 */
final class NativeUnit
{
    private readonly int $startedAt;

    private bool $finished = false;

    /**
     * @param  Closure(): void  $onFinish  releases the one-unit-at-a-time latch
     */
    public function __construct(
        private readonly NativeRuntime $runtime,
        private readonly int $handle,
        private readonly float $elapsedBeforeAdoptionMs,
        private readonly float $keepProfileAboveMs,
        private readonly bool $includeStacks,
        private readonly int $topFunctions,
        private readonly int $maxStackNodes,
        private readonly Closure $onFinish,
    ) {
        $this->startedAt = hrtime(true);
    }

    /**
     * Includes whatever the unit had already been running before this
     * object adopted it — under `cbox_telemetry.auto` that is the entire
     * framework bootstrap, and it is usually the part worth profiling.
     */
    public function elapsedMs(): float
    {
        return $this->elapsedBeforeAdoptionMs + (hrtime(true) - $this->startedAt) / 1_000_000;
    }

    /**
     * End the unit. Call this BEFORE ending the span — the operation
     * aggregates and counters it returns are attached to that span.
     *
     * @param  bool  $sampled  the sampling decision IN FORCE NOW, which is
     *                         not necessarily the one the unit opened under:
     *                         a per-route `Sample::never()` drops every span
     *                         of this trace, and a profile nobody can line up
     *                         against a trace is not worth materialising
     */
    public function finish(bool $sampled = true): ?NativeResult
    {
        if ($this->finished) {
            return null;
        }

        $this->release();

        $keepProfile = $sampled && $this->elapsedMs() >= $this->keepProfileAboveMs;

        return NativeResult::fromArray(
            $this->runtime->finish($this->handle, $keepProfile, $keepProfile && $this->includeStacks),
            $this->topFunctions,
            $this->maxStackNodes,
        );
    }

    /**
     * End the unit and keep nothing — an Octane worker being reset between
     * requests, or a command whose span was discarded. The native state has
     * to be closed either way; leaving it open would let one request's
     * samples bleed into the next.
     */
    public function discard(): void
    {
        if ($this->finished) {
            return;
        }

        $this->release();

        $this->runtime->finish($this->handle);
    }

    private function release(): void
    {
        $this->finished = true;

        ($this->onFinish)();
    }
}
