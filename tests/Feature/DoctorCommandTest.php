<?php

declare(strict_types=1);
use Cbox\Telemetry\TelemetryManager;

it('passes with the array store and no exporters', function () {
    $this->artisan('telemetry:doctor')
        ->expectsOutputToContain('Metric store [array]')
        ->expectsOutputToContain('All checks passed')
        ->assertSuccessful();
});

it('warns about an open prometheus endpoint', function () {
    config()->set('telemetry.prometheus.allowed_ips', []);

    $this->artisan('telemetry:doctor')
        ->expectsOutputToContain('OPEN — no IP allowlist')
        ->assertSuccessful();
});

it('reports when telemetry is disabled', function () {
    config()->set('telemetry.enabled', false);

    app()->forgetInstance(TelemetryManager::class);

    $this->artisan('telemetry:doctor')
        ->expectsOutputToContain('DISABLED')
        ->assertSuccessful();
});
