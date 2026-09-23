<?php

declare(strict_types=1);

use Cbox\Telemetry\Support\FrameworkBoot;

afterEach(fn () => FrameworkBoot::flush());

it('measures from the boot start to now on the first claim', function () {
    FrameworkBoot::flush(startedAt: 1000.0);

    expect(FrameworkBoot::claim(now: 1000.125))->toEqualWithDelta(125.0, 0.001);
});

it('gives every later request in the process nothing', function () {
    // A long-lived runtime: LARAVEL_START is the worker's, and the second
    // request 30 s later did not wait for any boot.
    FrameworkBoot::flush(startedAt: 1000.0);

    FrameworkBoot::claim(now: 1000.1);

    expect(FrameworkBoot::claim(now: 1030.0))->toBeNull()
        ->and(FrameworkBoot::claim(now: 1030.5))->toBeNull();
});

it('reports nothing without a known boot start', function () {
    // No LARAVEL_START in the test runner — Octane's situation too.
    expect(FrameworkBoot::claim())->toBeNull();
});

it('refuses implausible durations', function (float $now) {
    FrameworkBoot::flush(startedAt: 1000.0);

    expect(FrameworkBoot::claim(now: $now))->toBeNull();
})->with([
    'a worker idle for minutes' => 1120.0,
    'a clock that went backwards' => 999.0,
]);
