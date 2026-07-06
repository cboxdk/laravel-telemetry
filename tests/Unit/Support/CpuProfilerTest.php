<?php

declare(strict_types=1);

use Cbox\Telemetry\Support\CpuProfiler;

it('is a silent no-op without the excimer extension', function () {
    if (extension_loaded('excimer')) {
        $this->markTestSkipped('This asserts the no-extension path; excimer is loaded here.');
    }

    $profiler = CpuProfiler::start();

    expect($profiler->stop())->toBeNull();
});

it('samples real CPU time and aggregates by function', function () {
    if (! extension_loaded('excimer')) {
        $this->markTestSkipped('ext-excimer is not installed.');
    }

    $profiler = CpuProfiler::start(0.0005);

    $end = microtime(true) + 0.05;
    while (microtime(true) < $end) {
        // Busy-loop so the sampler has something to catch.
    }

    $top = $profiler->stop();

    expect($top)->not->toBeNull()
        ->and($top)->not->toBeEmpty()
        ->and($top[0])->toHaveKeys(['function', 'samples']);
})->group('excimer');
