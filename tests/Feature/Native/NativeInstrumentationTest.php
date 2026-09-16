<?php

declare(strict_types=1);

use Cbox\Telemetry\Contracts\NativeRuntime;
use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Http\Middleware\Sample;
use Cbox\Telemetry\Instrumentation\CommandInstrumentation;
use Cbox\Telemetry\Native\NativeProfiler;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Testing\FakeNativeRuntime;
use Cbox\Telemetry\Tracing\SpanKind;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class NativeUnitTestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void {}
}

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);

    $this->native = new FakeNativeRuntime;
    $this->app->instance(NativeRuntime::class, $this->native);
    $this->app->forgetInstance(NativeProfiler::class);

    Route::get('/native/{id}', fn (string $id) => ['id' => $id]);
});

function nativeServerSpans(CollectingExporter $collector): array
{
    $spans = [];

    foreach ($collector->batches() as $batch) {
        foreach ($batch->spans as $span) {
            if ($span->kind === SpanKind::Server) {
                $spans[] = $span;
            }
        }
    }

    return $spans;
}

function nativeEvents(CollectingExporter $collector, string $name): array
{
    $events = [];

    foreach ($collector->batches() as $batch) {
        foreach ($batch->events as $event) {
            if ($event->name === $name) {
                $events[] = $event;
            }
        }
    }

    return $events;
}

it('opens a native unit for a request and labels it with the trace context', function () {
    $this->get('/native/7')->assertOk();

    $span = nativeServerSpans($this->collector)[0];

    expect($this->native->begun)->toHaveCount(1)
        ->and($this->native->begun[0]['unit'])->toBe('http')
        ->and($this->native->begun[0]['trace_id'])->toBe($span->traceId)
        ->and($this->native->begun[0]['span_id'])->toBe($span->spanId)
        ->and($this->native->begun[0]['sampled'])->toBeTrue()
        ->and($this->native->begun[0]['profile'])->toBeTrue();
});

it('puts native operation timing on the request span', function () {
    $this->get('/native/7')->assertOk();

    $attributes = nativeServerSpans($this->collector)[0]->attributes();

    expect($attributes['pdo.connect.count'])->toBe(1)
        ->and($attributes['pdo.connect.duration_ms'])->toBe(8.3)
        ->and($attributes['curl.exec.count'])->toBe(2)
        ->and($attributes['curl.exec.max_ms'])->toBe(18.0)
        ->and($attributes['php.gc.runs'])->toBe(3)
        ->and($attributes['php.gc.collected'])->toBe(128);
});

/**
 * Traces are sampled; a connection-establishment regression has to be
 * visible without one. It does not have to be visible per route to be
 * visible, which is why these carry only operation and unit.
 */
it('records operation metrics with bounded labels', function () {
    $this->get('/native/7');

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());

    expect($families)->toHaveKey('runtime.operations')
        ->and($families)->toHaveKey('runtime.operation.duration')
        ->and($families)->toHaveKey('runtime.gc.runs');

    $calls = collect($families['runtime.operations']->samples)
        ->keyBy(fn ($sample) => $sample->labels['operation']);

    expect($calls['pdo.connect']->value)->toBe(1.0)
        ->and($calls['curl.exec']->value)->toBe(2.0)
        ->and(array_keys($calls['pdo.connect']->labels))->toBe(['operation', 'unit']);
});

it('keeps no profile for a request below the tail threshold', function () {
    config()->set('telemetry.profiling.min_duration_ms', 60_000);

    $this->get('/native/7');
    Telemetry::flush();

    expect($this->native->finished[0]['profile'])->toBeFalse()
        ->and(nativeEvents($this->collector, 'profile.captured'))->toBe([]);
});

it('reports a native profile for a slow request', function () {
    $this->app->instance(NativeRuntime::class, $this->native = FakeNativeRuntime::withProfile());
    $this->app->forgetInstance(NativeProfiler::class);

    config()->set('telemetry.profiling.min_duration_ms', 0);

    $this->get('/native/7');
    Telemetry::flush();

    $events = nativeEvents($this->collector, 'profile.captured');

    expect($events)->toHaveCount(1)
        ->and($events[0]->attributes['profile.source'])->toBe('native')
        ->and($events[0]->attributes['http.route'])->toBe('/native/{id}')
        ->and($events[0]->attributes['profile.clock'])->toBe('cpu')
        ->and($events[0]->attributes['profile.sample_count'])->toBe(814)
        ->and($events[0]->attributes['profile.confidence'])->toBeLessThan(1.0)
        ->and(json_decode($events[0]->attributes['profile.top_functions'], true)[0]['function'])
        ->toBe('App\\Services\\Pricing::calculate');
});

it('asks for the call tree only when stacks are configured', function () {
    $this->app->instance(NativeRuntime::class, $this->native = FakeNativeRuntime::withProfile());
    $this->app->forgetInstance(NativeProfiler::class);

    config()->set('telemetry.profiling.min_duration_ms', 0);
    config()->set('telemetry.native.stacks', true);

    $this->get('/native/7');

    expect($this->native->finished[0]['stacks'])->toBeTrue();
});

it('opens no unit at all when the extension is absent', function () {
    $this->app->instance(NativeRuntime::class, $absent = new FakeNativeRuntime(available: false));
    $this->app->forgetInstance(NativeProfiler::class);

    $this->get('/native/7')->assertOk();

    expect($absent->begun)->toBe([])
        ->and(nativeServerSpans($this->collector)[0]->attributes())->not->toHaveKey('pdo.connect.count');
});

it('opens no unit when the native layer is switched off', function () {
    config()->set('telemetry.native.enabled', false);

    $this->get('/native/7')->assertOk();

    expect($this->native->begun)->toBe([]);
});

it('leaves operation attributes off when operations are switched off', function () {
    config()->set('telemetry.native.operations', false);

    $this->get('/native/7')->assertOk();

    $attributes = nativeServerSpans($this->collector)[0]->attributes();

    expect($attributes)->not->toHaveKey('pdo.connect.count')
        ->and($attributes['php.gc.runs'])->toBe(3);
});

/**
 * Units do not nest: a second begin() abandons the first one in the C,
 * silently and with its samples. A sync job dispatched inside a request is
 * the easiest way to trigger that from Laravel without meaning to.
 */
it('refuses to open a second unit inside an open one', function () {
    config()->set('queue.default', 'sync');

    Route::get('/native/dispatch', function () {
        NativeUnitTestJob::dispatch();

        return 'ok';
    });

    $this->get('/native/dispatch')->assertOk();

    expect($this->native->begun)->toHaveCount(1)
        ->and($this->native->begun[0]['unit'])->toBe('http');
});

it('opens a queue unit for a job running in a worker', function () {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\Jobs\AnyJob');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('payload')->andReturn([]);

    app('queue');

    $events = app('events');
    $events->dispatch(new JobProcessing('redis', $job));
    $events->dispatch(new JobProcessed('redis', $job));

    expect($this->native->begun)->toHaveCount(1)
        ->and($this->native->begun[0]['unit'])->toBe('queue')
        ->and($this->native->finished)->toHaveCount(1);

    // The unit label is the extension's own, not the call site's guess.
    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());
    $sample = collect($families['runtime.operations']->samples)->first();

    expect(array_keys($sample->labels))->toBe(['operation', 'unit']);
});

it('opens a command unit, but not for a command that hosts its own units', function () {
    $events = app('events');

    (new CommandInstrumentation(app()))->register($events);

    $events->dispatch(new CommandStarting('queue:work', new ArrayInput([]), new NullOutput));
    $events->dispatch(new CommandFinished('queue:work', new ArrayInput([]), new NullOutput, 0));

    expect($this->native->begun)->toBe([]);

    $events->dispatch(new CommandStarting('app:report', new ArrayInput([]), new NullOutput));
    $events->dispatch(new CommandFinished('app:report', new ArrayInput([]), new NullOutput, 0));

    expect($this->native->begun)->toHaveCount(1)
        ->and($this->native->begun[0]['unit'])->toBe('command');
});

/**
 * An Octane request that died with a unit open would otherwise hold the
 * one-unit-at-a-time rule shut for the rest of the worker's life.
 */
it('releases the latch when a worker is reset mid-unit', function () {
    $profiler = $this->app->make(NativeProfiler::class);

    expect($profiler->begin('http'))->not->toBeNull()
        ->and($profiler->begin('http'))->toBeNull();

    $profiler->flushRequestState();

    expect($profiler->begin('http'))->not->toBeNull()
        ->and($this->native->finished)->toHaveCount(1);
});

it('matches the excluded-command patterns with wildcards', function () {
    $profiler = $this->app->make(NativeProfiler::class);

    expect($profiler->hostsItsOwnUnits('queue:work'))->toBeTrue()
        ->and($profiler->hostsItsOwnUnits('horizon:supervisor'))->toBeTrue()
        ->and($profiler->hostsItsOwnUnits('octane:start'))->toBeTrue()
        ->and($profiler->hostsItsOwnUnits('app:import'))->toBeFalse();
});

/**
 * A unit opens whether or not a sampler exists — the handle is real and
 * finish() reports `profiling => false`. Treating the handle as proof that
 * profiling was covered silenced an installed ext-excimer on every host
 * with the extension but no usable timer (macOS, `profiler.enabled=0`).
 */
it('knows the difference between having a unit and having a profiler', function () {
    $profiler = $this->app->make(NativeProfiler::class);

    expect($profiler->profiles())->toBeTrue();

    config()->set('telemetry.native.profile', false);
    expect($profiler->profiles())->toBeFalse()
        // …and the unit still opens: operations, counters and crash context
        // do not depend on the sampler.
        ->and($profiler->begin('http'))->not->toBeNull();
});

it('leaves profiling to excimer when the extension has no sampler', function () {
    $native = new FakeNativeRuntime;
    $native->status['profiler_enabled'] = false;

    $this->app->instance(NativeRuntime::class, $native);
    $this->app->forgetInstance(NativeProfiler::class);

    $this->get('/native/7')->assertOk();

    expect($this->app->make(NativeProfiler::class)->profiles())->toBeFalse()
        // The unit is still opened and still measured.
        ->and($native->begun)->toHaveCount(1)
        ->and($native->begun[0]['profile'])->toBeFalse();
});

/**
 * With cbox_telemetry.auto the unit starts at RINIT and begin() adopts it,
 * which is the whole point — the samples worth having are the bootstrap's.
 * Timing from the adoption instead threw exactly those away: an 800 ms
 * bootstrap plus 10 ms of routing read as a 10 ms unit.
 */
it('counts the bootstrap an adopted unit already measured', function () {
    $this->native->status['unit_handle'] = 7;
    $this->native->status['unit_automatic'] = true;

    $unit = $this->app->make(NativeProfiler::class)->begin('http');

    // The anchor is the SAPI's request start — this process's start here —
    // so the adopted unit's elapsed time is everything since, not the zero
    // a freshly opened unit would report.
    expect($unit?->elapsedMs())->toBeGreaterThan(1.0);
});

it('measures only its own time when no unit was adopted', function () {
    $unit = $this->app->make(NativeProfiler::class)->begin('http');

    expect($unit?->elapsedMs())->toBeLessThan(1000.0);
});

/**
 * The decision that matters is the one in force at finish(): a per-route
 * Sample::never() drops every span of the trace, and a profile with no
 * trace to line it up against is not worth materialising.
 */
it('keeps no profile for a trace that resampled itself away', function () {
    $this->app->instance(NativeRuntime::class, $this->native = FakeNativeRuntime::withProfile());
    $this->app->forgetInstance(NativeProfiler::class);

    config()->set('telemetry.profiling.min_duration_ms', 0);

    // Its own path: /native/{id} is registered first and would match.
    Route::middleware(Sample::never())->get('/unsampled-native', fn () => 'ok');

    $this->get('/unsampled-native')->assertOk();
    Telemetry::flush();

    expect($this->native->finished[0]['profile'])->toBeFalse()
        ->and(nativeEvents($this->collector, 'profile.captured'))->toBe([]);
});

/**
 * Laravel has an attempt path with no outcome event at all: a job that
 * releases itself and then throws is neither processed, nor failed, nor
 * released BY THE WORKER — so none of the listeners that close a unit ever
 * run, and every later job in that worker would be refused a unit.
 */
it('closes a unit for an attempt that never reported an outcome', function () {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\Jobs\SelfReleasingJob');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('payload')->andReturn([]);

    app('queue');
    $events = app('events');

    $events->dispatch(new JobProcessing('redis', $job));
    // No JobProcessed/JobFailed/JobReleasedAfterException — only the
    // finally-block event Laravel dispatches for every attempt.
    $events->dispatch(new JobAttempted('redis', $job));

    expect($this->native->finished)->toHaveCount(1);

    // And the next job still gets a unit.
    $events->dispatch(new JobProcessing('redis', $job));
    $events->dispatch(new JobProcessed('redis', $job));

    expect($this->native->begun)->toHaveCount(2);
});
