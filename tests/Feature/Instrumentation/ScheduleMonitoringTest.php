<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Instrumentation\ScheduleInstrumentation;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Events\Dispatcher;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

beforeEach(function () {
    // An isolated dispatcher — the provider already registered the
    // instrumentation on the app's own one.
    $this->events = new Dispatcher;
    (new ScheduleInstrumentation(app()))->register($this->events);
});

/**
 * @return array<string, float> gauge value per task label
 */
function scheduleGauge(string $name): array
{
    $family = collect(Telemetry::collect())->first(fn ($f) => $f->name() === $name);

    return $family === null ? [] : collect($family->samples)
        ->mapWithKeys(fn ($s) => [$s->labels['task'] ?? '' => $s->value])
        ->all();
}

function startScheduleRun(Dispatcher $events): void
{
    $events->dispatch(new CommandStarting('schedule:run', new ArrayInput([]), new NullOutput));
}

it('publishes when each task was last due when schedule:run starts', function () {
    app(Schedule::class)->command('inspire')->hourly();
    app(Schedule::class)->command('cache:prune-stale-tags')->everyMinute();

    startScheduleRun($this->events);

    $due = scheduleGauge('schedule.task.previous_due');
    $listed = scheduleGauge('schedule.task.listed');
    $now = time();

    expect($due['inspire'])->toBe((float) (intdiv($now, 3600) * 3600))
        ->and($due['cache:prune-stale-tags'])->toBe((float) (intdiv($now, 60) * 60))
        ->and($listed['inspire'])->toBeGreaterThanOrEqual($now - 1)
        ->and(scheduleGauge('schedule.run.last_seen')[''])->toBeGreaterThanOrEqual($now - 1);
});

it('leaves out tasks that do not run in this environment', function () {
    app(Schedule::class)->command('inspire')->hourly()->environments(['nowhere']);

    startScheduleRun($this->events);

    expect(scheduleGauge('schedule.task.previous_due'))->not->toHaveKey('inspire');
});

it('ignores other commands', function () {
    app(Schedule::class)->command('inspire')->hourly();

    $this->events->dispatch(new CommandStarting('migrate', new ArrayInput([]), new NullOutput));

    expect(scheduleGauge('schedule.task.previous_due'))->toBe([]);
});

it('tracks a run from start to success', function () {
    $task = app(Schedule::class)->command('inspire')->everyMinute();

    $this->events->dispatch(new ScheduledTaskStarting($task));

    expect(scheduleGauge('schedule.task.running_since')['inspire'])->toBeGreaterThan(0)
        ->and(scheduleGauge('schedule.task.last_seen')['inspire'])->toBeGreaterThan(0);

    $this->events->dispatch(new ScheduledTaskFinished($task, 0.01));

    expect(scheduleGauge('schedule.task.running_since')['inspire'])->toBe(0.0)
        ->and(scheduleGauge('schedule.task.last_succeeded')['inspire'])->toBeGreaterThan(0);

    $delay = collect(Telemetry::collect())->first(fn ($f) => $f->name() === 'schedule.task.start_delay');

    expect($delay)->not->toBeNull();
});

it('clears running_since on failure without counting a success', function () {
    $task = app(Schedule::class)->command('inspire')->everyMinute();

    $this->events->dispatch(new ScheduledTaskStarting($task));
    $this->events->dispatch(new ScheduledTaskFailed($task, new RuntimeException('boom')));

    expect(scheduleGauge('schedule.task.running_since')['inspire'])->toBe(0.0)
        ->and(scheduleGauge('schedule.task.last_succeeded'))->not->toHaveKey('inspire');
});

it('counts a skip as seen — the scheduler decided, it did not miss', function () {
    $task = app(Schedule::class)->command('inspire')->everyMinute();

    $this->events->dispatch(new ScheduledTaskSkipped($task));

    expect(scheduleGauge('schedule.task.last_seen')['inspire'])->toBeGreaterThan(0);
});

it('marks background tasks as started too', function () {
    $task = app(Schedule::class)->command('inspire')->everyMinute()->runInBackground();

    $this->events->dispatch(new ScheduledTaskStarting($task));

    expect(scheduleGauge('schedule.task.running_since')['inspire'])->toBeGreaterThan(0);
});

it('publishes nothing when monitoring is off', function () {
    $events = new Dispatcher;
    (new ScheduleInstrumentation(app()))->register($events, monitor: false);

    $task = app(Schedule::class)->command('inspire')->everyMinute();

    startScheduleRun($events);
    $events->dispatch(new ScheduledTaskStarting($task));
    $events->dispatch(new ScheduledTaskFinished($task, 0.01));

    expect(scheduleGauge('schedule.task.previous_due'))->toBe([])
        ->and(scheduleGauge('schedule.task.running_since'))->toBe([])
        ->and(scheduleGauge('schedule.task.last_seen'))->toBe([]);
});
