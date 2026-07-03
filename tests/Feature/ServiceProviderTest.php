<?php

declare(strict_types=1);

use Cbox\Telemetry\Contracts\MetricStore;
use Cbox\Telemetry\Exporters\Otlp\OtlpTransport;
use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Metrics\Registry;
use Cbox\Telemetry\Metrics\Stores\ArrayMetricStore;
use Cbox\Telemetry\Metrics\Stores\NullMetricStore;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Testing\TelemetryFake;
use Cbox\Telemetry\Tracing\Tracer;

it('registers the manager as a singleton behind the facade', function () {
    expect(app(TelemetryManager::class))->toBe(app('telemetry'))
        ->and(Telemetry::enabled())->toBeTrue();
});

it('binds the configured metric store', function () {
    expect(app(MetricStore::class))->toBeInstanceOf(ArrayMetricStore::class);
});

it('records metrics through the facade', function () {
    Telemetry::counter('orders.created', 'Orders created')->inc();

    $families = Telemetry::collect();

    expect($families)->toHaveCount(1)
        ->and($families[0]->name())->toBe('orders.created')
        ->and($families[0]->samples[0]->value)->toBe(1.0);
});

it('swaps in a fake with assertions', function () {
    $fake = Telemetry::fake();

    Telemetry::counter('orders.created')->inc();

    expect(app(TelemetryManager::class))->toBeInstanceOf(TelemetryFake::class);

    $fake->assertCounterIncremented('orders.created');
});

it('is a no-op when disabled', function () {
    config()->set('telemetry.enabled', false);

    // Rebuild the singletons with the new config (in production the
    // config is set before boot, so they are born disabled).
    app()->forgetInstance(MetricStore::class);
    app()->forgetInstance(Tracer::class);
    app()->forgetInstance(Registry::class);
    app()->forgetInstance(TelemetryManager::class);

    $manager = app()->make(TelemetryManager::class);

    expect($manager->enabled())->toBeFalse()
        ->and(app(MetricStore::class))->toBeInstanceOf(NullMetricStore::class);

    // All of these must be safe no-ops.
    $manager->counter('orders.created')->inc();
    $manager->event('something');
    $span = $manager->span('work');
    $span->end();

    expect($manager->collect())->toBeEmpty()
        ->and($manager->tracer()->drain())->toBeEmpty();
});

it('sends the OTLP bearer token as an Authorization header when configured', function () {
    config()->set('telemetry.otlp.headers', ['Authorization' => 'Bearer demo-token']);

    $transport = app(OtlpTransport::class);

    $headers = (fn () => $this->headers)->call($transport);

    expect($headers)->toHaveKey('Authorization')
        ->and($headers['Authorization'])->toBe('Bearer demo-token');
});
