<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Tests;

/**
 * The base case for the fail-safety suite: every optional instrumentation
 * switched ON before the application boots.
 *
 * Setting these in the test body and re-booting the provider does not
 * work — the listeners are registered during boot, and a second boot
 * drops the singletons the first one armed. Half the listeners then
 * silently do not exist, and a suite asserting "nothing threw" passes
 * because nothing ran.
 */
abstract class HostileTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('telemetry.instrument.cache', true);
        $app['config']->set('telemetry.instrument.cache_spans', true);
        $app['config']->set('telemetry.instrument.redis', true);
        $app['config']->set('telemetry.instrument.jobs', true);
        $app['config']->set('telemetry.instrument.views', true);
        $app['config']->set('telemetry.instrument.gates', true);

        // Off by default because most applications do not want a span
        // per artisan invocation — which also meant the whole console
        // path, the one that runs unattended, was never dispatched at
        // anything in this suite.
        $app['config']->set('telemetry.instrument.commands', true);
    }
}
