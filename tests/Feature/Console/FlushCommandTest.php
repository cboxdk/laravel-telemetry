<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Testing\CollectingExporter;

it('exports metrics to the configured exporters', function () {
    $collector = new CollectingExporter;
    Telemetry::addExporter($collector);

    Telemetry::counter('orders.created')->inc(5);

    $this->artisan('telemetry:flush')->assertSuccessful();

    $metrics = collect($collector->batches())->flatMap(fn ($batch) => $batch->metrics);

    expect($metrics->firstWhere(fn ($family) => $family->name() === 'orders.created'))
        ->not->toBeNull();
});

it('optionally wipes the store after flushing', function () {
    Telemetry::counter('orders.created')->inc(5);

    $this->artisan('telemetry:flush', ['--wipe' => true])->assertSuccessful();

    expect(Telemetry::collect())->toBeEmpty();
});

it('reports when telemetry is disabled', function () {
    config()->set('telemetry.enabled', false);

    app()->forgetInstance(TelemetryManager::class);

    $this->artisan('telemetry:flush')
        ->expectsOutputToContain('disabled')
        ->assertSuccessful();
});
