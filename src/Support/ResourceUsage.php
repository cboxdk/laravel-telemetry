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

        if (self::processMetricsAreCheap()) {
            $pid = getmypid();

            if ($pid !== false) {
                $trackerId = ProcessMetrics::start($pid)->getValueOr(null);
            }
        }

        return new self(self::cpuNow(), is_string($trackerId) ? $trackerId : null);
    }

    /**
     * Whether reading the OS process footprint is cheap enough to do
     * twice per request.
     *
     * cboxdk/system-metrics reads `/proc/{pid}/stat` on Linux, which is
     * a file read and costs microseconds. Everywhere else it shells out
     * to `ps` — and a subprocess is ~28ms, twice per unit of work,
     * which on a developer's Mac made this package the dominant cost of
     * every request and every queue job. Fifty-six milliseconds to
     * measure something that took two.
     *
     * The PHP built-ins below cost nothing and work everywhere, so the
     * OS footprint is simply skipped where taking it is expensive:
     * `rssPeakBytes` and `cpuUtilization` come back null, exactly as
     * they do when the package is not installed at all.
     *
     * `telemetry.instrument.resources_process` overrides the decision
     * either way for anyone who knows their platform better than this
     * does.
     */
    private static function processMetricsAreCheap(): bool
    {
        if (! class_exists(ProcessMetrics::class)) {
            return false;
        }

        $configured = config('telemetry.instrument.resources_process');

        // Null means "decide for me", which is the default and the only
        // value that varies by platform.
        if ($configured !== null) {
            return Cast::flag($configured);
        }

        return PHP_OS_FAMILY === 'Linux';
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

    /**
     * The process' CURRENT resident set size — the number that grows
     * job after job when a worker leaks. Null without
     * cboxdk/system-metrics or when the platform source fails.
     */
    public static function currentRssBytes(): ?int
    {
        // The same platform check as start() and measure(). It was
        // missing here, and this is called after every asynchronous
        // job — so a queue worker on a platform without /proc shelled
        // out to `ps` once per job while the request path had already
        // been fixed not to.
        if (! self::processMetricsAreCheap()) {
            return null;
        }

        $pid = getmypid();

        if ($pid === false) {
            return null;
        }

        $snapshot = ProcessMetrics::snapshot($pid)->getValueOr(null);

        return $snapshot?->resources->memoryRssBytes;
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
