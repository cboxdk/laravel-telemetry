<?php

declare(strict_types=1);

use Cbox\Telemetry\Exporters\Spool\ArraySpool;
use Cbox\Telemetry\Exporters\Spool\Spool;
use Cbox\Telemetry\Facades\Telemetry;

beforeEach(function () {
    config()->set('telemetry.otlp.spool.enabled', true);
    config()->set('telemetry.exporters', ['otlp']);

    $this->spool = new ArraySpool;
    $this->app->instance(Spool::class, $this->spool);
});

it('spools serialized spans instead of posting at terminate', function () {
    // Rebuild the manager so the spool-enabled exporter set applies.
    $this->refreshApplication();
    config()->set('telemetry.otlp.spool.enabled', true);
    config()->set('telemetry.exporters', ['otlp']);
    $this->app->instance(Spool::class, $spool = new ArraySpool);

    Telemetry::span('order.place', fn () => true);
    Telemetry::event('order.placed', ['plan' => 'pro']);
    Telemetry::flush();

    expect($spool->size())->toBe(2);

    [$traces, $logs] = $spool->pop(2);

    expect($traces['signal'])->toBe('traces')
        ->and($traces['payload']['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['name'])->toBe('order.place')
        ->and($logs['signal'])->toBe('logs')
        ->and($logs['payload']['resourceLogs'][0]['scopeLogs'][0]['logRecords'])->toHaveCount(1);
});

it('drains the spool from the flush command', function () {
    $this->refreshApplication();
    config()->set('telemetry.otlp.spool.enabled', true);
    config()->set('telemetry.exporters', ['otlp']);
    // Unreachable endpoint: entries must be requeued, never lost.
    config()->set('telemetry.otlp.endpoint', 'http://127.0.0.1:59999');
    config()->set('telemetry.otlp.timeout', 0.2);
    config()->set('telemetry.otlp.connect_timeout', 0.2);
    $this->app->instance(Spool::class, $spool = new ArraySpool);

    Telemetry::span('order.place', fn () => true);
    Telemetry::flush();

    expect($spool->size())->toBe(1);

    // Held back, not delivered — and the command says so and exits
    // non-zero, because cron is the only thing watching.
    $this->artisan('telemetry:flush')
        ->expectsOutputToContain('requeued')
        ->assertFailed();

    expect($spool->size())->toBe(1);
});

it('wires opt-in metric buffering through the configured exporter', function () {
    $this->refreshApplication();
    config()->set('telemetry.otlp.spool.enabled', true);
    config()->set('telemetry.otlp.spool.metrics', true);
    config()->set('telemetry.exporters', ['otlp']);
    $this->app->instance(Spool::class, $spool = new ArraySpool);

    Telemetry::gauge('runtime.memory')->set(512.0);
    Telemetry::flushMetrics();

    $entries = $spool->pop(10);
    expect($entries)->not->toBeEmpty()
        ->and($entries[0]['signal'])->toBe('metrics');
    $metrics = $entries[0]['payload']['resourceMetrics'][0]['scopeMetrics'][0]['metrics'];
    $memory = array_values(array_filter($metrics, fn (array $metric): bool => $metric['name'] === 'runtime.memory'));
    expect($memory)->toHaveCount(1)
        ->and($memory[0]['gauge']['dataPoints'][0]['asDouble'])->toBe(512.0);
});
