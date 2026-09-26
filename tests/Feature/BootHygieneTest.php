<?php

declare(strict_types=1);

use Cbox\SystemMetrics\SystemMetrics;
use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Support\ResourceDetector;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\TelemetryServiceProvider;

/**
 * The service provider does registrations, not work.
 *
 * Building the manager builds the resource, which reads `.git` and probes
 * container and environment files. Doing that while the application is
 * still booting costs every request the setup cost of a feature it may
 * never use — and it is the one rule this package states about itself, so
 * it is worth a test rather than a convention.
 */
it('does not build the manager while booting', function () {
    expect($this->app->resolved(TelemetryManager::class))->toBeFalse();
});

it('registers the host provider anyway, once something asks for the manager', function () {
    // Deferring the registration must not lose it: the provider is armed
    // by a resolution hook, so the families appear the first time anyone
    // collects. The suite disables the provider by default, so this
    // re-runs the boot-time pass with it on.
    config()->set('telemetry.providers.system.enabled', true);

    (new TelemetryServiceProvider($this->app))->boot();

    $names = array_map(static fn ($family): string => $family->name(), Telemetry::collect());

    expect($names)->toContain('system.memory.usage');
})->skip(fn (): bool => ! class_exists(SystemMetrics::class), 'needs cboxdk/system-metrics');

it('lets the operator name the host over the detected one', function () {
    // `OTEL_RESOURCE_ATTRIBUTES` is the OpenTelemetry standard override
    // and outranks detection. It could not: the resource seeded
    // `host.name` from `gethostname()` first, and the merge keeps what is
    // already there. In a container whose hostname is a random id, the
    // real machine's name never arrived.
    putenv('OTEL_RESOURCE_ATTRIBUTES=host.name=web-3.fleet');
    ResourceDetector::flush();

    $provider = new TelemetryServiceProvider($this->app);
    $resource = (fn () => $this->buildResource($this->app))->call($provider);

    expect($resource['host.name'])->toBe('web-3.fleet');

    putenv('OTEL_RESOURCE_ATTRIBUTES');
    ResourceDetector::flush();
});
