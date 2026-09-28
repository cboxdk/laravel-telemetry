<?php

declare(strict_types=1);

use Cbox\Telemetry\Support\ResourceDetector;
use Cbox\Telemetry\TelemetryServiceProvider;
use Illuminate\Foundation\Application;

/**
 * `service.instance.id` is the only thing Prometheus's and Mimir's OTLP
 * receivers turn into the `instance` label. Without it, two hosts that
 * each keep their own counters push them into one series whose value
 * jumps between the two totals, and every jump down reads as a reset.
 * What the default names is covered in the ServiceInstance unit tests;
 * these cover who gets to override it.
 */
function resourceOf(Application $app): array
{
    ResourceDetector::flush();

    $provider = new TelemetryServiceProvider($app);

    return (fn () => $this->buildResource($app))->call($provider);
}

afterEach(function () {
    putenv('OTEL_RESOURCE_ATTRIBUTES');
    ResourceDetector::flush();
});

it('takes the configured instance id over everything else', function () {
    putenv('OTEL_RESOURCE_ATTRIBUTES=service.instance.id=from-env');
    config()->set('telemetry.service.instance_id', 'web-7');

    expect(resourceOf($this->app)['service.instance.id'])->toBe('web-7');
});

it('takes a detected instance id over the default', function () {
    putenv('OTEL_RESOURCE_ATTRIBUTES=service.instance.id=pod-abc');

    expect(resourceOf($this->app)['service.instance.id'])->toBe('pod-abc');
});

it('ignores an empty configured instance id', function () {
    config()->set('telemetry.service.instance_id', '');

    $resource = resourceOf($this->app);

    expect($resource['service.instance.id'])->toBe($resource['host.name']);
});

it('defaults to the resolved host name, not a per-process id', function () {
    // The resolved one: an operator's host.name override moves the
    // instance with it. A per-process id would multiply every series by
    // the number of FPM workers.
    putenv('OTEL_RESOURCE_ATTRIBUTES=host.name=web-3.fleet');

    expect(resourceOf($this->app)['service.instance.id'])->toBe('web-3.fleet');
});

it('defaults to the host name with detection off', function () {
    config()->set('telemetry.resource_detection', false);

    expect(resourceOf($this->app)['service.instance.id'])->toBe((string) gethostname());
});

it('always sets one, even on a shared store', function () {
    config()->set('telemetry.store', 'redis');
    config()->set('database.redis.default', ['host' => 'redis.internal', 'port' => '6379']);

    expect(resourceOf($this->app)['service.instance.id'])->toMatch('/^redis-[0-9a-f]{12}$/');
});

it('still honours an explicit instance id on a shared store', function () {
    config()->set('telemetry.store', 'redis');
    config()->set('database.redis.default', ['host' => 'redis.internal']);
    config()->set('telemetry.service.instance_id', 'fleet');

    expect(resourceOf($this->app)['service.instance.id'])->toBe('fleet');
});
