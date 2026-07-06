<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

/**
 * Per-request/per-job CPU profiling via ext-excimer (a statistical
 * sampling profiler — PECL `excimer`, not bundled, `class_exists`-guarded
 * everywhere). A no-op wrapper when the extension is absent, so this is
 * never a hard dependency.
 *
 * Tail-based like `traces.details.mode = tail`: profiling always starts
 * (its own sampling means the overhead is small and roughly constant —
 * an excimer profiler running is far cheaper than the alternative of
 * deciding "was this worth profiling?" after the fact), but the result
 * is only kept and reported for requests/jobs that turn out to be slow
 * (`profiling.min_duration_ms`) — fast, uninteresting units of work
 * never pay for a profile nobody will look at.
 *
 * Reports a bounded "top N functions by sample count" summary, not a
 * full pprof export — this package has no opinion on a profiling
 * backend (OTel's profiling signal is still stabilizing across the
 * ecosystem); the summary is enough to see where a slow request/job
 * spent its CPU without one.
 *
 * @internal built against the excimer 1.x API (`aggregateByFunction()`
 * shipped in 1.2.0, 2022) — every excimer call is wrapped in
 * `FailSafe::guard` so a version mismatch degrades to "no profile
 * captured", never a broken request.
 */
final class CpuProfiler
{
    private ?\ExcimerProfiler $profiler;

    private function __construct(?\ExcimerProfiler $profiler)
    {
        $this->profiler = $profiler;
    }

    public static function start(float $periodSeconds = 0.001): self
    {
        if (! extension_loaded('excimer')) {
            return new self(null);
        }

        return FailSafe::guard(function () use ($periodSeconds): self {
            $profiler = new \ExcimerProfiler;
            $profiler->setPeriod(max(0.0001, $periodSeconds));
            $profiler->setEventType(EXCIMER_CPU);
            $profiler->start();

            return new self($profiler);
        }) ?? new self(null);
    }

    /**
     * Stop profiling and return the top functions by sample count, or
     * null when excimer is unavailable, the profile is empty, or
     * anything about the extension's API didn't match what this class
     * expects.
     *
     * @return list<array{function: string, samples: int}>|null
     */
    public function stop(int $topFunctions = 20): ?array
    {
        $profiler = $this->profiler;

        if ($profiler === null) {
            return null;
        }

        return FailSafe::guard(function () use ($profiler, $topFunctions): ?array {
            $profiler->stop();

            $log = $profiler->getLog();

            if (! method_exists($log, 'aggregateByFunction')) {
                return null;
            }

            $counts = [];

            foreach ($log->aggregateByFunction() as $function => $count) {
                if (is_string($function) && (is_int($count) || is_float($count))) {
                    $counts[$function] = (int) $count;
                }
            }

            if ($counts === []) {
                return null;
            }

            arsort($counts);

            $top = [];

            foreach (array_slice($counts, 0, max(1, $topFunctions), true) as $function => $count) {
                $top[] = ['function' => $function, 'samples' => $count];
            }

            return $top;
        });
    }
}
