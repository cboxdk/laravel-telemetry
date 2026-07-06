<?php

declare(strict_types=1);

use Cbox\Telemetry\Exporters\Spool\Spool;
use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Testing\CollectingExporter;

final class ThrowingFlushSpool implements Spool
{
    public function push(array $entry): void {}

    public function pop(int $count): array
    {
        throw new RuntimeException('spool backend unreachable');
    }

    public function requeue(array $entries): void {}

    public function size(): int
    {
        return 0;
    }
}

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

it('fails cleanly instead of dumping a stack trace when the spool throws', function () {
    config()->set('telemetry.otlp.spool.enabled', true);
    app()->instance(Spool::class, new ThrowingFlushSpool);

    $this->artisan('telemetry:flush')
        ->expectsOutputToContain('Failed to ship the spool')
        ->assertFailed();
});

it('reports when telemetry is disabled', function () {
    config()->set('telemetry.enabled', false);

    app()->forgetInstance(TelemetryManager::class);

    $this->artisan('telemetry:flush')
        ->expectsOutputToContain('disabled')
        ->assertSuccessful();
});
