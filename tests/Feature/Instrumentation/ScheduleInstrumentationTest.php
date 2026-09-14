<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Instrumentation\ScheduleInstrumentation;
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

/**
 * getSummaryForDisplay() returns the whole built command line when no
 * description is set — every argument, plus the redirection. Keeping that as a
 * metric LABEL meant the arguments became the cardinality, and the arguments
 * are exactly what varies:
 *
 *   $schedule->command('reports:send --date='.now()->toDateString())
 *      -> a new label value every day
 *   foreach ($tenants as $t) { $schedule->command("tenant:sync {$t->id}") }
 *      -> one per tenant, 15 histogram series each, kept forever
 */
it('labels a scheduled task by its command name, not its arguments', function () {
    $name = (new ReflectionMethod(ScheduleInstrumentation::class, 'taskName'))
        ->getClosure(new ScheduleInstrumentation(app()));

    $task = new class
    {
        public ?string $description = null;

        public string $summary = '';

        public function getSummaryForDisplay(): string
        {
            return $this->summary;
        }
    };

    $task->summary = "'/usr/bin/php' 'artisan' reports:send --date=2026-09-14 > '/dev/null' 2>&1";
    expect($name($task))->toBe('reports:send');

    $task->summary = "'/usr/bin/php' 'artisan' tenant:sync 48213 > '/dev/null' 2>&1";
    expect($name($task))->toBe('tenant:sync');

    // A `2` inside the command name is not a redirection. Treating it as one
    // silently merged two different commands into one series.
    $task->summary = "'/usr/bin/php' 'artisan' reports:v2:send --date=2026-09-14 > '/dev/null' 2>&1";
    expect($name($task))->toBe('reports:v2:send');

    // exec() summaries are arbitrary shell lines: stripping the quoted binary
    // promotes the ARGUMENT, and a varying argument is the unbounded case.
    $task->summary = "'/usr/bin/printf' alice";
    expect($name($task))->toBe('exec');

    $task->summary = '/srv/tenants/acme/run.sh --tenant=acme';
    expect($name($task))->toBe('exec');

    // A description the app set is the app's own cardinality, so it wins.
    $task->description = 'nightly reconciliation';
    expect($name($task))->toBe('nightly reconciliation');
});
