<?php

declare(strict_types=1);

use Cbox\Telemetry\Tests\HostileTestCase;
use Cbox\Telemetry\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

// Its own folder, and its own case, because the instrumentation it
// exercises has to be switched on BEFORE the application boots — the
// listeners are registered during boot, and setting config in a test body
// and re-booting the provider drops the singletons the first boot armed.
// Half the listeners then quietly do not exist, and a suite asserting
// "nothing threw" passes because nothing ran.
pest()->extend(HostileTestCase::class)->in('FailSafety');
