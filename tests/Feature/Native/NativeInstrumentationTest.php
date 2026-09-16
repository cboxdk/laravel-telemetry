<?php

declare(strict_types=1);

use Cbox\Telemetry\Contracts\NativeRuntime;
use Cbox\Telemetry\Facades\Telemetry;
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
