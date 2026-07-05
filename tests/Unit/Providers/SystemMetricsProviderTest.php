<?php

declare(strict_types=1);

use Cbox\Telemetry\Metrics\Registry;
use Cbox\Telemetry\Metrics\Stores\ArrayMetricStore;
use Cbox\Telemetry\Providers\SystemMetricsProvider;

function systemMetricsRegistry(): Registry
{
    return new Registry(new ArrayMetricStore, [10, 100, 1000]);
}

it('names itself for TelemetryManager::provider()', function () {
    expect((new SystemMetricsProvider)->name())->toBe('cbox.system-metrics');
});

it('registers host memory, filesystem, network and load gauges without throwing', function () {
    $registry = systemMetricsRegistry();

    (new SystemMetricsProvider(cpuInterval: 0.0))->register($registry);

    $names = collect($registry->collect())->map(fn ($family) => $family->name());

    expect($names)->toContain('system.memory.usage')
        ->toContain('system.memory.utilization')
        ->toContain('system.cpu.load_average')
        ->toContain('system.filesystem.usage')
        ->toContain('system.network.io');
});

it('skips the cpu.utilization gauge when cpuInterval is 0', function () {
    $registry = systemMetricsRegistry();

    (new SystemMetricsProvider(cpuInterval: 0.0))->register($registry);

    expect(collect($registry->collect())->map(fn ($family) => $family->name()))
        ->not->toContain('system.cpu.utilization');
});

it('registers the cpu.utilization gauge when cpuInterval is positive', function () {
    $registry = systemMetricsRegistry();

    (new SystemMetricsProvider(cpuInterval: 0.01))->register($registry);

    expect(collect($registry->collect())->map(fn ($family) => $family->name()))
        ->toContain('system.cpu.utilization');
});
