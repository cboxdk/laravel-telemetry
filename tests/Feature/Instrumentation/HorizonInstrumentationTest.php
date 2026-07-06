<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Testing\CollectingExporter;
use Illuminate\Support\Collection;
use Laravel\Horizon\Events\JobsMigrated;
use Laravel\Horizon\Events\LongWaitDetected;
use Laravel\Horizon\Events\MasterSupervisorLooped;
use Laravel\Horizon\Events\MasterSupervisorOutOfMemory;
use Laravel\Horizon\Events\SupervisorLooped;
use Laravel\Horizon\Events\SupervisorOutOfMemory;
use Laravel\Horizon\Events\SupervisorProcessRestarting;
use Laravel\Horizon\Events\WorkerProcessRestarting;
use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\Supervisor;
use Laravel\Horizon\SupervisorOptions;
use Laravel\Horizon\SupervisorProcess;
use Laravel\Horizon\WorkerProcess;

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);
});

function horizonFamilies(): Collection
{
    return collect(Telemetry::collect())->keyBy(fn ($f) => $f->name());
}

function fakeSupervisor(string $name = 'supervisor-1', bool $working = true, int $processes = 3): Supervisor
{
    $supervisor = Mockery::mock(Supervisor::class)->makePartial();
    $supervisor->name = $name;
    $supervisor->options = new SupervisorOptions($name, 'redis', 'default');
    $supervisor->working = $working;
    $supervisor->shouldReceive('totalProcessCount')->andReturn($processes);

    return $supervisor;
}

it('pushes process count and paused state on every supervisor loop', function () {
    app('events')->dispatch(new SupervisorLooped(fakeSupervisor('supervisor-1', working: true, processes: 4)));

    $families = horizonFamilies();

    $processSample = $families['horizon.supervisor.processes']->samples[0];
    expect($processSample->labels['supervisor'])->toBe('supervisor-1')
        ->and($processSample->labels['connection'])->toBe('redis')
        ->and($processSample->labels['queue'])->toBe('default')
        ->and($processSample->value)->toBe(4.0);

    expect($families['horizon.supervisor.paused']->samples[0]->value)->toBe(0.0);
});

it('reports paused supervisors as 1', function () {
    app('events')->dispatch(new SupervisorLooped(fakeSupervisor('supervisor-1', working: false)));

    expect(horizonFamilies()['horizon.supervisor.paused']->samples[0]->value)->toBe(1.0);
});

it('pushes master supervisor state on every master loop', function () {
    $master = Mockery::mock(MasterSupervisor::class)->makePartial();
    $master->name = 'master-1';
    $master->working = true;
    $master->supervisors = collect([fakeSupervisor(), fakeSupervisor('supervisor-2')]);

    app('events')->dispatch(new MasterSupervisorLooped($master));

    $families = horizonFamilies();

    expect($families['horizon.master.paused']->samples[0]->value)->toBe(0.0)
        ->and($families['horizon.master.supervisors']->samples[0]->value)->toBe(2.0)
        ->and($families['horizon.master.supervisors']->samples[0]->labels['master'])->toBe('master-1');
});

it('counts and logs a long-wait detection', function () {
    app('events')->dispatch(new LongWaitDetected('redis', 'emails', 45));

    $families = horizonFamilies();
    $sample = $families['horizon.long_wait.detected']->samples[0];

    expect($sample->labels)->toBe(['connection' => 'redis', 'queue' => 'emails'])
        ->and($sample->value)->toBe(1.0);

    $event = collect($this->collector->batches())->flatMap(fn ($b) => $b->events)->first();

    expect($event->name)->toBe('horizon.long_wait.detected')
        ->and($event->attributes['wait.seconds'])->toBe(45);
});

it('counts worker and supervisor process restarts separately', function () {
    $worker = Mockery::mock(WorkerProcess::class)->makePartial();
    app('events')->dispatch(new WorkerProcessRestarting($worker));

    $supervisorProcess = Mockery::mock(SupervisorProcess::class)->makePartial();
    app('events')->dispatch(new SupervisorProcessRestarting($supervisorProcess));

    $samples = horizonFamilies()['horizon.process.restarts']->samples;
    $byType = collect($samples)->keyBy(fn ($s) => $s->labels['type']);

    expect($byType['worker']->value)->toBe(1.0)
        ->and($byType['supervisor']->value)->toBe(1.0);
});

it('counts and logs out-of-memory events for both supervisor and master', function () {
    app('events')->dispatch(new SupervisorOutOfMemory(fakeSupervisor()));

    $master = Mockery::mock(MasterSupervisor::class)->makePartial();
    app('events')->dispatch(new MasterSupervisorOutOfMemory($master));

    $samples = horizonFamilies()['horizon.process.out_of_memory']->samples;
    $byType = collect($samples)->keyBy(fn ($s) => $s->labels['type']);

    expect($byType['supervisor']->value)->toBe(1.0)
        ->and($byType['master']->value)->toBe(1.0);

    $events = collect($this->collector->batches())->flatMap(fn ($b) => $b->events)->pluck('name');

    expect($events)->toContain('horizon.process.out_of_memory');
});

it('counts migrated jobs by the number of payloads', function () {
    $event = (new JobsMigrated(['payload-a', 'payload-b', 'payload-c']))
        ->connection('redis')
        ->queue('emails');

    app('events')->dispatch($event);

    $sample = horizonFamilies()['horizon.jobs.migrated']->samples[0];

    expect($sample->value)->toBe(3.0)
        ->and($sample->labels)->toBe(['connection' => 'redis', 'queue' => 'emails']);
});
