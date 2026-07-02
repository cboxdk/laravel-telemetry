<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\SpanStatus;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);
});

function scheduleSpans(CollectingExporter $collector): array
{
    Telemetry::flush();

    return collect($collector->batches())
        ->flatMap(fn ($batch) => $batch->spans)
        ->filter(fn ($span) => str_starts_with($span->name, 'schedule '))
        ->values()
        ->all();
}

it('wraps scheduled task runs in spans with resource usage and outcome counters', function () {
    $task = app(Schedule::class)->command('inspire')->everyMinute();

    $events = app('events');
    $events->dispatch(new ScheduledTaskStarting($task));
    $events->dispatch(new ScheduledTaskFinished($task, 0.05));

    $spans = scheduleSpans($this->collector);

    expect($spans)->toHaveCount(1)
        ->and($spans[0]->name)->toContain('inspire')
        ->and($spans[0]->status())->toBe(SpanStatus::Ok)
        ->and($spans[0]->attributes()['schedule.cron'])->toBe('* * * * *')
        ->and($spans[0]->attributes())->toHaveKeys(['php.memory.peak_bytes', 'php.cpu.time_ms']);

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());

    expect($families)->toHaveKeys(['schedule.task.duration', 'schedule.tasks.processed']);
});

it('records failed task runs with the exception', function () {
    $task = app(Schedule::class)->command('inspire')->everyMinute();

    $events = app('events');
    $events->dispatch(new ScheduledTaskStarting($task));
    $events->dispatch(new ScheduledTaskFailed($task, new RuntimeException('cron boom')));

    $spans = scheduleSpans($this->collector);

    expect($spans[0]->status())->toBe(SpanStatus::Error)
        ->and($spans[0]->events()[0]->attributes['exception.message'])->toBe('cron boom');

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());

    expect($families)->toHaveKey('schedule.tasks.failed');
});

it('counts skipped tasks — the outcome most instrumentation misses', function () {
    $task = app(Schedule::class)->command('inspire')->everyMinute();

    app('events')->dispatch(new ScheduledTaskSkipped($task));

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());

    expect($families)->toHaveKey('schedule.tasks.skipped')
        ->and($families['schedule.tasks.skipped']->samples[0]->value)->toBe(1.0);
});

it('ignores background tasks to avoid double collection', function () {
    $task = app(Schedule::class)->command('inspire')->everyMinute()->runInBackground();

    app('events')->dispatch(new ScheduledTaskStarting($task));

    expect(scheduleSpans($this->collector))->toBeEmpty();
});
