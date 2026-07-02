<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

use Cbox\SystemMetrics\ProcessMetrics;

/**
 * Per-request/per-job resource measurement.
 *
 * Two complementary sources:
 *
 * - PHP built-ins (always): getrusage CPU delta + the PHP allocator's
 *   peak, reset per unit of work so long-lived workers report THIS
 *   request/job — not process lifetime.
 * - cboxdk/system-metrics (when installed): a ProcessMetrics tracker
 *   around the unit of work adds the process' real OS footprint — peak
 *   RSS (which sees non-PHP allocations the PHP allocator misses) and
 *   CPU utilization for the interval. Same mechanism
 *   cboxdk/laravel-queue-metrics uses for per-job metrics.
 */
final readonly class ResourceUsage
{
    private function __construct(
        private float $cpuMs,
        private ?string $trackerId,
    ) {}

    public static function start(): self
    {
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        $trackerId = null;

        if (class_exists(ProcessMetrics::class)) {
            $pid = getmypid();

            if ($pid !== false) {
                $trackerId = ProcessMetrics::start($pid)->getValueOr(null);
            }
        }

        return new self(self::cpuNow(), is_string($trackerId) ? $trackerId : null);
    }

    /**
     * Measure since start(). `rssPeakBytes`/`cpuUtilization` are null
     * when cboxdk/system-metrics is not installed (or the platform
     * source fails); the PHP-built-in numbers are always present.
     *
     * @return array{memoryPeakBytes: int, cpuTimeMs: float, rssPeakBytes: int|null, cpuUtilization: float|null}
     */
    public function measure(): array
    {
        $rssPeakBytes = null;
        $cpuUtilization = null;

        if ($this->trackerId !== null) {
            $stats = ProcessMetrics::stop($this->trackerId)->getValueOr(null);

            if ($stats !== null) {
                $rssPeakBytes = $stats->peak->memoryRssBytes;
                $cpuUtilization = round($stats->delta->cpuUsagePercentage() / 100, 4);
            }
        }

        return [
            'memoryPeakBytes' => memory_get_peak_usage(true),
            'cpuTimeMs' => round(max(0.0, self::cpuNow() - $this->cpuMs), 3),
            'rssPeakBytes' => $rssPeakBytes,
            'cpuUtilization' => $cpuUtilization,
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
