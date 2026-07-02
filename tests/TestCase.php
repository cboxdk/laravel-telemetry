<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Tests;

use Cbox\Telemetry\TelemetryServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            TelemetryServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('telemetry.store', 'array');
        $app['config']->set('telemetry.exporters', []);
        $app['config']->set('telemetry.providers.system.enabled', false);
    }
}
