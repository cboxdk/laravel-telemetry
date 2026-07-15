<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Tests;

/**
 * Boots the app with telemetry disabled BEFORE the provider runs, the way
 * production sees TELEMETRY_ENABLED=false — boot-time registrations must
 * stay behavior-neutral for app code (macro, Blade directives).
 */
abstract class DisabledTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('telemetry.enabled', false);
    }
}
