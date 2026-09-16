<?php

declare(strict_types=1);

use Cbox\Telemetry\Contracts\NativeRuntime;
use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Native\CrashReporter;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Testing\FakeNativeRuntime;
use Cbox\Telemetry\Testing\RejectingExporter;

function crashRecord(array $overrides = []): array
{
    return [
        'signal' => 11,
        'signal_name' => 'SIGSEGV',
        'si_code' => 1,
        'fault_address' => '0x0',
        'program_counter' => '0x10a2b3c4d',
        'module_base' => '0x10a2b0000',
        'module_offset' => 13901,
        'pid' => 4711,
        'timestamp_ns' => 1_789_000_000_000_000_000,
        'unit' => 'queue',
        'unit_duration_ns' => 812_000_000,
        'trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736',
        'span_id' => '00f067aa0ba902b7',
        'operation' => 'pdo.connect',
        'operation_elapsed_ns' => 180_000_000,
        'breadcrumbs' => [
            ['seq' => 1, 'type' => 'unit.begin', 'label' => 'queue', 'ts_ns' => 1],
            ['seq' => 2, 'type' => 'op.begin', 'label' => 'pdo.connect', 'ts_ns' => 2],
        ],
        ...$overrides,
    ];
}

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);

    $this->native = new FakeNativeRuntime;
    $this->app->instance(NativeRuntime::class, $this->native);
});

/**
 * The whole point of the record: the process died below the level PHP can
 * see, and the crash still lands in the trace that was in flight.
 */
it('reports a crash record as a fatal event in the trace it died in', function () {
    $this->native->crashes = [crashRecord()];

    $this->app->make(CrashReporter::class)->drain();
    Telemetry::flush();

    $events = collect($this->collector->batches())->flatMap(fn ($batch) => $batch->events);
    $event = $events->firstWhere('name', 'crash.recorded');

    expect($event)->not->toBeNull()
        ->and($event->traceId)->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($event->spanId)->toBe('00f067aa0ba902b7')
        ->and($event->severityText)->toBe('FATAL')
        ->and($event->timeUnixNano)->toBe(1_789_000_000_000_000_000)
        ->and($event->attributes['crash.signal_name'])->toBe('SIGSEGV')
        ->and($event->attributes['crash.pid'])->toBe(4711)
        ->and($event->attributes['crash.unit'])->toBe('queue')
        ->and($event->attributes['crash.unit_duration_ms'])->toBe(812.0)
        ->and($event->attributes['crash.operation'])->toBe('pdo.connect')
        ->and($event->attributes['crash.operation_elapsed_ms'])->toBe(180.0)
        ->and(json_decode($event->attributes['crash.breadcrumbs'], true))->toHaveCount(2);
});

it('counts crashes by signal and unit', function () {
    $this->native->crashes = [crashRecord(), crashRecord(['signal_name' => 'SIGABRT', 'signal' => 6])];

    $this->app->make(CrashReporter::class)->drain();

    $family = collect(Telemetry::collect())->first(fn ($f) => $f->name() === 'runtime.crashes');

    expect($family)->not->toBeNull()
        ->and(collect($family->samples)->pluck('labels.signal')->all())->toContain('SIGSEGV', 'SIGABRT');
});

/**
 * si_addr is only a fault address for a signal the hardware raised. For a
 * sent one the union holds the sender's pid, which is not an address and
 * must not be reported as one — the extension nulls it, and nothing here
 * may invent a value in its place.
 */
it('omits a fault address the record does not have', function () {
    $this->native->crashes = [crashRecord(['fault_address' => null, 'module_offset' => null])];

    $reported = $this->app->make(CrashReporter::class)->drain();

    expect($reported[0])->not->toHaveKey('crash.fault_address')
        ->and($reported[0])->not->toHaveKey('crash.module_offset');
});

it('drains nothing when crash collection is off', function () {
    config()->set('telemetry.native.crashes', false);
    $this->native->crashes = [crashRecord()];

    expect($this->app->make(CrashReporter::class)->drain())->toBe([])
        ->and($this->native->crashes)->toHaveCount(1);
});

it('drains nothing when the extension is absent', function () {
    $this->app->instance(NativeRuntime::class, new FakeNativeRuntime(available: false));

    expect($this->app->make(CrashReporter::class)->drain())->toBe([]);
});

it('reports crash records from the flush command', function () {
    $this->native->crashes = [crashRecord()];

    $this->artisan('telemetry:flush')->assertSuccessful();

    expect($this->native->crashes)->toBe([]);

    $events = collect($this->collector->batches())->flatMap(fn ($batch) => $batch->events);

    expect($events->firstWhere('name', 'crash.recorded'))->not->toBeNull();
});

it('prints and reports pending crashes from telemetry:crashes', function () {
    $this->native->crashes = [crashRecord()];

    $this->artisan('telemetry:crashes')
        ->expectsOutputToContain('SIGSEGV')
        ->assertSuccessful();

    expect($this->native->crashes)->toBe([]);
});

it('says so when the extension is not installed', function () {
    $this->app->instance(NativeRuntime::class, new FakeNativeRuntime(available: false));

    $this->artisan('telemetry:crashes')
        ->expectsOutputToContain('not loaded')
        ->assertSuccessful();
});

/**
 * The drain already consumed them, so a rejected batch is a record that no
 * longer exists anywhere. Under cron the exit code is the only thing anyone
 * reads.
 */
it('fails the crash command when the batch was not accepted', function () {
    Telemetry::addExporter(new RejectingExporter);
    $this->native->crashes = [crashRecord()];

    $this->artisan('telemetry:crashes')
        ->expectsOutputToContain('that data is gone')
        ->assertFailed();
});
