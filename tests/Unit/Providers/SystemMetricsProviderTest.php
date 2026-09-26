<?php

declare(strict_types=1);

use Cbox\Telemetry\Metrics\MetricType;
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

it('registers host memory, filesystem, network and load instruments without throwing', function () {
    $registry = systemMetricsRegistry();

    (new SystemMetricsProvider(cpuInterval: 0.0))->register($registry);

    $names = collect($registry->collect())->map(fn ($family) => $family->name());

    expect($names)->toContain('system.memory.usage')
        ->toContain('system.memory.utilization')
        ->toContain('system.filesystem.usage')
        ->toContain('system.network.io')
        // semconv names the three load windows separately, not one metric
        // with a `period` label.
        ->toContain('system.cpu.load_average.1m')
        ->toContain('system.cpu.load_average.5m')
        ->toContain('system.cpu.load_average.15m')
        ->not->toContain('system.cpu.load_average');
});

it('gives each host instrument the shape semconv asks for', function () {
    $registry = systemMetricsRegistry();

    (new SystemMetricsProvider(cpuInterval: 0.0))->register($registry);

    $types = collect($registry->collect())
        ->mapWithKeys(fn ($family) => [$family->name() => $family->type()]);

    // A sum that goes down: adds across hosts, must never be averaged.
    expect($types['system.memory.usage'])->toBe(MetricType::UpDownCounter)
        ->and($types['system.filesystem.usage'])->toBe(MetricType::UpDownCounter)
        // Monotonic since boot: only a counter may be rate()'d.
        ->and($types['system.network.io'])->toBe(MetricType::Counter)
        // A fraction is a level, not a sum.
        ->and($types['system.memory.utilization'])->toBe(MetricType::Gauge)
        ->and($types['system.cpu.load_average.1m'])->toBe(MetricType::Gauge);
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
