<?php

declare(strict_types=1);

use Cbox\SystemMetrics\ProcessMetrics;
use Cbox\Telemetry\Support\ResourceUsage;

/**
 * `instrument.resources` is on by default and runs twice per request and
 * twice per queue job. What it costs depends entirely on where the OS
 * footprint comes from: cboxdk/system-metrics reads `/proc/{pid}/stat`
 * on Linux, and shells out to `ps` everywhere else.
 *
 * A subprocess is ~28ms. Twice per unit of work, that made this package
 * the dominant cost of every request on a developer's Mac — fifty-six
 * milliseconds to measure something that took two.
 */
it('skips the OS footprint where taking it means spawning a process', function () {
    config()->set('telemetry.instrument.resources_process', null);

    $measured = ResourceUsage::start()->measure();

    // The PHP built-ins are always there; they cost nothing and work
    // everywhere.
    expect($measured['memoryPeakBytes'])->toBeInt()
        ->and($measured['cpuTimeMs'])->toBeFloat();

    if (PHP_OS_FAMILY === 'Linux') {
        // /proc is a file read. Take it.
        expect($measured)->toHaveKey('rssPeakBytes');
    } else {
        // Absent, exactly as when system-metrics is not installed at all.
        expect($measured['rssPeakBytes'])->toBeNull()
            ->and($measured['cpuUtilization'])->toBeNull();
    }
});

it('never costs more than a millisecond on the default setting', function () {
    config()->set('telemetry.instrument.resources_process', null);

    $start = hrtime(true);

    for ($i = 0; $i < 20; $i++) {
        ResourceUsage::start()->measure();
    }

    $perCall = (hrtime(true) - $start) / 1_000_000 / 20;

    // Generous by two orders of magnitude against the `ps` path, which
    // measured ~56ms for the same pair of calls.
    expect($perCall)->toBeLessThan(1.0);
});

it('takes it anyway when the operator says so', function () {
    config()->set('telemetry.instrument.resources_process', true);

    $measured = ResourceUsage::start()->measure();

    // Someone who knows their platform better than a PHP_OS_FAMILY
    // check does can have the footprint on any platform.
    expect($measured['rssPeakBytes'])->toBeInt();
})->skip(fn (): bool => ! class_exists(ProcessMetrics::class), 'needs cboxdk/system-metrics');

it('takes nothing when the operator says not to', function () {
    config()->set('telemetry.instrument.resources_process', false);

    $measured = ResourceUsage::start()->measure();

    expect($measured['rssPeakBytes'])->toBeNull()
        ->and($measured['memoryPeakBytes'])->toBeInt();
});
