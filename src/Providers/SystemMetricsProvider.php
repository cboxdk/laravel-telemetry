<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Providers;

use Cbox\SystemMetrics\DTO\Metrics\Cpu\CpuDelta;
use Cbox\SystemMetrics\DTO\Metrics\LoadAverageSnapshot;
use Cbox\SystemMetrics\DTO\Metrics\Memory\MemorySnapshot;
use Cbox\SystemMetrics\SystemMetrics;
use Cbox\Telemetry\Contracts\TelemetryProvider;
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

    public function register(Registry $registry): void
    {
        $registry->gauge(
            'system.memory.usage',
            fn (): array => $this->memoryUsage(),
            description: 'Memory in use by state',
            unit: 'By',
        );

        $registry->gauge(
            'system.memory.utilization',
            fn (): array => $this->memoryUtilization(),
            description: 'Fraction of memory in use (0-1)',
            unit: '1',
        );

        $registry->gauge(
            'system.cpu.load_average',
            fn (): array => $this->loadAverage(),
            description: 'System load average',
            unit: '1',
        );

        $registry->gauge(
            'system.filesystem.usage',
            fn (): array => $this->filesystemUsage(),
            description: 'Filesystem bytes by state',
            unit: 'By',
        );

        $registry->gauge(
            'system.network.io',
            fn (): array => $this->networkIo(),
            description: 'Cumulative network bytes by direction (use rate() in PromQL)',
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
            [(float) $storage->usedBytes(), ['state' => 'used']],
            [(float) $storage->availableBytes(), ['state' => 'free']],
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
            [(float) $network->totalBytesReceived(), ['direction' => 'receive']],
            [(float) $network->totalBytesSent(), ['direction' => 'transmit']],
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
            [(float) $memory->usedBytes, ['state' => 'used']],
            [(float) $memory->freeBytes, ['state' => 'free']],
            [(float) $memory->cachedBytes, ['state' => 'cached']],
            [(float) $memory->buffersBytes, ['state' => 'buffers']],
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
            [$memory->usedPercentage() / 100, ['state' => 'used']],
        ];
    }

    /**
     * @return list<array{0: float, 1: array<string, string>}>
     */
    private function loadAverage(): array
    {
        $load = SystemMetrics::loadAverage()->getValueOr(null);

        if (! $load instanceof LoadAverageSnapshot) {
            return [];
        }

        return [
            [$load->oneMinute, ['period' => '1m']],
            [$load->fiveMinutes, ['period' => '5m']],
            [$load->fifteenMinutes, ['period' => '15m']],
        ];
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
