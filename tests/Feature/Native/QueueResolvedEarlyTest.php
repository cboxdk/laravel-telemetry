<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Tests\Feature\Native;

use Cbox\Telemetry\Contracts\NativeRuntime;
use Cbox\Telemetry\Native\NativeProfiler;
use Cbox\Telemetry\Testing\FakeNativeRuntime;
use Cbox\Telemetry\Tests\TestCase;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * The application whose boot order resolves `queue` before telemetry boots.
 *
 * `callAfterResolving` then fires immediately, so the instrumentation's own
 * JobProcessing listener is registered FIRST and the worker's pre-job reset
 * second — and the reset, which flushes stateful instrumentation, discarded
 * every job's native unit before the job body ran. It reproduces only in
 * this order, which is why it needs its own case rather than a config flag.
 */
final class QueueResolvedEarlyTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('telemetry.native.enabled', true);

        // Before the package's provider boots.
        $app->make('queue');
    }

    #[Test]
    public function a_job_keeps_its_native_unit_when_the_queue_resolved_early(): void
    {
        $native = new FakeNativeRuntime;

        $this->app->instance(NativeRuntime::class, $native);
        $this->app->forgetInstance(NativeProfiler::class);

        $job = Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn('App\Jobs\AnyJob');
        $job->shouldReceive('getQueue')->andReturn('default');
        $job->shouldReceive('attempts')->andReturn(1);
        $job->shouldReceive('payload')->andReturn([]);

        $events = $this->app->make('events');

        $events->dispatch(new JobProcessing('redis', $job));

        $this->assertCount(1, $native->begun);
        $this->assertSame([], $native->finished, 'the unit was discarded before the job ran');

        $events->dispatch(new JobProcessed('redis', $job));

        $this->assertCount(1, $native->finished);
    }
}
