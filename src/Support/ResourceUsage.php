<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

/**
 * Per-request/per-job resource measurement: peak memory and CPU time
 * delta, using PHP built-ins (getrusage + memory_reset_peak_usage) —
 * process-accurate and dependency-free.
 *
 * The peak counter is process-global, so it is reset at the start of
 * each unit of work; long-lived workers (FPM, queue, Octane) therefore
 * report the peak of THIS request/job, not of the process lifetime.
 */
final readonly class ResourceUsage
{
    private function __construct(
        private float $cpuMs,
    ) {}

    public static function start(): self
    {
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        return new self(self::cpuNow());
    }

    /**
     * Measure since start(): peak memory (bytes) and CPU time (ms,
     * user + system).
     *
     * @return array{memoryPeakBytes: int, cpuTimeMs: float}
     */
    public function measure(): array
    {
        return [
            'memoryPeakBytes' => memory_get_peak_usage(true),
            'cpuTimeMs' => round(max(0.0, self::cpuNow() - $this->cpuMs), 3),
        ];
    }

    private static function cpuNow(): float
    {
        if (! function_exists('getrusage')) {
            return 0.0;
        }

        $usage = getrusage();

        if ($usage === false) {
            return 0.0;
        }

        return ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1000.0
            + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1000.0;
    }
}
