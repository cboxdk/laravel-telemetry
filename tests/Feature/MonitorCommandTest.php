<?php

declare(strict_types=1);

use Cbox\Telemetry\Support\ExportOutcome;
use Cbox\Telemetry\Support\ExportReport;
use Cbox\Telemetry\Support\ExportResult;
use Cbox\Telemetry\TelemetryManager;

/**
 * telemetry:monitor sampled a host, failed to ship it, and exited zero.
 *
 * Read from cron the exit code is the whole report — this is the same blind
 * spot telemetry:flush had, one command over, and it survived that fix
 * because nothing pointed at it.
 */
it('exits non-zero from a single run the backend refused', function (): void {
    $telemetry = Mockery::mock(TelemetryManager::class);
    $telemetry->shouldReceive('enabled')->andReturn(true);
    $telemetry->shouldReceive('gauge')->andReturnSelf();
    $telemetry->shouldReceive('counter')->andReturnSelf();
    $telemetry->shouldReceive('flush')->andReturn(
        new ExportReport([ExportOutcome::of('otlp', ExportResult::failed('HTTP 400: nope'))]),
    );

    app()->instance(TelemetryManager::class, $telemetry);

    $this->artisan('telemetry:monitor --once')
        ->expectsOutputToContain('were not accepted')
        ->assertExitCode(1);
});

it('still exits zero when the batch lands', function (): void {
    $telemetry = Mockery::mock(TelemetryManager::class);
    $telemetry->shouldReceive('enabled')->andReturn(true);
    $telemetry->shouldReceive('gauge')->andReturnSelf();
    $telemetry->shouldReceive('counter')->andReturnSelf();
    $telemetry->shouldReceive('flush')->andReturn(new ExportReport);

    app()->instance(TelemetryManager::class, $telemetry);

    $this->artisan('telemetry:monitor --once')->assertExitCode(0);
});

/**
 * A single run is always a FIRST sample: the process exits and $previousCpu
 * dies with it. Deltaing against a previous tick therefore never produced a
 * CPU number in cron mode — the mode the docs recommend for hosts without a
 * supervisor — and did so silently.
 */
it('reports cpu utilization from a single run', function (): void {
    $gauges = [];

    $telemetry = Mockery::mock(TelemetryManager::class);
    $telemetry->shouldReceive('enabled')->andReturn(true);
    $telemetry->shouldReceive('counter')->andReturnSelf();
    $telemetry->shouldReceive('flush')->andReturn(new ExportReport);
    $telemetry->shouldReceive('gauge')
        ->andReturnUsing(function (string $name) use (&$gauges, $telemetry) {
            $gauges[] = $name;

            return $telemetry;
        });
    $telemetry->shouldReceive('set')->andReturnSelf();

    app()->instance(TelemetryManager::class, $telemetry);

    $this->artisan('telemetry:monitor --once')->assertExitCode(0);

    expect($gauges)->toContain('system.cpu.utilization');
})->skipOnWindows();
