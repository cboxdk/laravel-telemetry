<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Providers;

use Cbox\SystemMetrics\DTO\Metrics\Cpu\CpuDelta;
use Cbox\SystemMetrics\DTO\Metrics\LoadAverageSnapshot;
use Cbox\SystemMetrics\DTO\Metrics\Memory\MemorySnapshot;
use Cbox\SystemMetrics\SystemMetrics;
use Cbox\Telemetry\Contracts\TelemetryProvider;
use Cbox\Telemetry\Metrics\MetricType;
use Cbox\Telemetry\Metrics\Registry;

/**
 * Host CPU, memory and load metrics via cboxdk/system-metrics — reading
 * /proc & sysfs directly, container-aware, no node_exporter required.
 *
 * Auto-registered when cboxdk/system-metrics is installed. Metric names
 * follow the OTel system semantic conventions.
 */
final readonly class SystemMetricsProvider implements TelemetryProvider
{
    public function __construct(
        private float $cpuInterval = 0.1,
    ) {}

    public function name(): string
    {
        return 'cbox.system-metrics';
    }

    /** semconv's three load-average metrics, and the snapshot field each reads. */
    private const LOAD_AVERAGE_WINDOWS = [
        '1m' => 'oneMinute',
        '5m' => 'fiveMinutes',
        '15m' => 'fifteenMinutes',
    ];

    public function register(Registry $registry): void
    {
        // semconv: an UpDownCounter, not a gauge. Bytes in use are a sum
        // that happens to go down — they add across hosts, and a gauge
        // would let a backend average them instead.
        $registry->observable(
            'system.memory.usage',
            fn (): array => $this->memoryUsage(),
            MetricType::UpDownCounter,
            description: 'Memory in use by state',
            unit: 'By',
        );

        $registry->gauge(
            'system.memory.utilization',
            fn (): array => $this->memoryUtilization(),
            description: 'Fraction of memory in use (0-1)',
            unit: '1',
        );

        // semconv names the three windows as three metrics rather than
        // one with a `period` label, and the UI and alerts that consume
        // them are ours. One query per window is the cost; being the same
        // series every other exporter in the room emits is the payoff.
        foreach (self::LOAD_AVERAGE_WINDOWS as $window => $index) {
            $registry->gauge(
                'system.cpu.load_average.'.$window,
                fn (): array => $this->loadAverage($index),
                description: "System load average over {$window}",
                unit: '{thread}',
            );
        }

        $registry->observable(
            'system.filesystem.usage',
            fn (): array => $this->filesystemUsage(),
            MetricType::UpDownCounter,
            description: 'Filesystem bytes by state',
            unit: 'By',
        );

        // semconv: a monotonic Counter. It already WAS cumulative — saying
        // so is what gets it a `_total` suffix, lets rate() handle a
        // reboot's reset instead of drawing a cliff, and stops anyone
        // averaging a number that only ever grows.
        $registry->observable(
            'system.network.io',
            fn (): array => $this->networkIo(),
            MetricType::Counter,
            description: 'Network bytes by direction since boot',
            unit: 'By',
        );

        if ($this->cpuInterval > 0) {
            $registry->gauge(
                'system.cpu.utilization',
                fn (): array => $this->cpuUtilization(),
                description: "CPU busy fraction (0-1), sampled over {$this->cpuInterval}s at collect time",
                unit: '1',
            );
        }
    }

    /**
     * @return list<array{0: float, 1: array<string, string>}>
     */
    private function filesystemUsage(): array
    {
        $storage = SystemMetrics::storage()->getValueOr(null);

        if ($storage === null) {
            return [];
        }

        return [
            [(float) $storage->usedBytes(), ['system.filesystem.state' => 'used']],
            [(float) $storage->availableBytes(), ['system.filesystem.state' => 'free']],
        ];
    }

    /**
     * @return list<array{0: float, 1: array<string, string>}>
     */
    private function networkIo(): array
    {
        $network = SystemMetrics::network()->getValueOr(null);

        if ($network === null) {
            return [];
        }

        return [
            [(float) $network->totalBytesReceived(), ['network.io.direction' => 'receive']],
            [(float) $network->totalBytesSent(), ['network.io.direction' => 'transmit']],
        ];
    }

    /**
     * @return list<array{0: float, 1: array<string, string>}>
     */
    private function memoryUsage(): array
    {
        $memory = SystemMetrics::memory()->getValueOr(null);

        if (! $memory instanceof MemorySnapshot) {
            return [];
        }

        return [
            [(float) $memory->usedBytes, ['system.memory.state' => 'used']],
            [(float) $memory->freeBytes, ['system.memory.state' => 'free']],
            [(float) $memory->cachedBytes, ['system.memory.state' => 'cached']],
            [(float) $memory->buffersBytes, ['system.memory.state' => 'buffers']],
        ];
    }

    /**
     * @return list<array{0: float, 1: array<string, string>}>
     */
    private function memoryUtilization(): array
    {
        $memory = SystemMetrics::memory()->getValueOr(null);

        if (! $memory instanceof MemorySnapshot) {
            return [];
        }

        return [
            [$memory->usedPercentage() / 100, ['system.memory.state' => 'used']],
        ];
    }

    /**
     * @return list<array{0: float, 1: array<string, string>}>
     */
    /**
     * @return list<array{0: float, 1: array<string, string>}>
     */
    private function loadAverage(string $index): array
    {
        $load = SystemMetrics::loadAverage()->getValueOr(null);

        if (! $load instanceof LoadAverageSnapshot) {
            return [];
        }

        return [[match ($index) {
            'oneMinute' => $load->oneMinute,
            'fiveMinutes' => $load->fiveMinutes,
            default => $load->fifteenMinutes,
        }, []]];
    }

    /**
     * @return list<array{0: float, 1: array<string, string>}>
     */
    private function cpuUtilization(): array
    {
        $delta = SystemMetrics::cpuUsage($this->cpuInterval)->getValueOr(null);

        if (! $delta instanceof CpuDelta) {
            return [];
        }

        return [
            [$delta->usagePercentage() / 100, []],
        ];
    }
}
