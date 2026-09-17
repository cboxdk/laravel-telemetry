<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Tests\Feature\Native;

use Cbox\Telemetry\Contracts\NativeRuntime;
use Cbox\Telemetry\Native\CrashReporter;
use Cbox\Telemetry\Testing\FakeNativeRuntime;
use Cbox\Telemetry\Tests\DisabledTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Draining DELETES. With telemetry off the manager buffers nothing and
 * exports nothing, so a drain would consume the one artefact a dead process
 * left behind and hand it to a sink that throws it away — the opposite of
 * what a disabled telemetry package is supposed to do, which is nothing.
 */
final class CrashDrainDisabledTest extends DisabledTestCase
{
    #[Test]
    public function it_leaves_crash_records_on_disk_when_telemetry_is_disabled(): void
    {
        $native = new FakeNativeRuntime;
        $native->crashes = [['signal' => 11, 'signal_name' => 'SIGSEGV', 'pid' => 4711, 'unit' => 'http']];

        $this->app->instance(NativeRuntime::class, $native);

        $this->assertSame([], $this->app->make(CrashReporter::class)->drain());
        $this->assertCount(1, $native->crashes);
    }

    #[Test]
    public function the_crashes_command_consumes_nothing_when_telemetry_is_disabled(): void
    {
        $native = new FakeNativeRuntime;
        $native->crashes = [['signal' => 11, 'signal_name' => 'SIGSEGV', 'pid' => 4711, 'unit' => 'http']];

        $this->app->instance(NativeRuntime::class, $native);

        $this->artisan('telemetry:crashes')
            ->expectsOutputToContain('Telemetry is disabled')
            ->assertSuccessful();

        $this->assertCount(1, $native->crashes);
    }
}
