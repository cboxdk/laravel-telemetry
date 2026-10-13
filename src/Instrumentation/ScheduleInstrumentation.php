<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Native\NativeProfiler;
use Cbox\Telemetry\Native\NativeReporter;
use Cbox\Telemetry\Native\NativeUnit;
use Cbox\Telemetry\Support\Cast;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\Support\ResourceUsage;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanStatus;
use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;

/**
 * Scheduled task monitoring: a span per task run with duration, peak
 * memory and CPU time, plus outcome counters — including SKIPPED tasks
 * (filters/withoutOverlapping), which most instrumentation misses.
 *
 * `schedule:run` executes tasks sequentially in one process, so state
 * (context, peak-memory counter) is reset per task to avoid metric
 * pollution between tasks. Tasks with runInBackground() finish in a
 * separate process (`schedule:finish`) and are deliberately not spanned
 * here — counting them would double-collect.
 *
 * Monitoring (`instrument.schedule_monitoring`): the outcome counters say
 * how the runs that HAPPENED went. They cannot say a task did not run at
 * all — a missed task produces no event. So every `schedule:run` also
 * publishes, per task, when cron last said it was due, and every start or
 * skip records when the task was last seen; the gap between the two is
 * "missed", and a start with no finish is "running since". The bundled
 * alert rules turn these gauges into missed / overrunning / scheduler-
 * stopped alerts. Gauges, in the shared store, because the question is
 * asked by Prometheus long after the process that knew the answer exited.
 */
final class ScheduleInstrumentation
{
    /** @var array<int, array{span: Span, usage: ResourceUsage|null, name: string, unit: NativeUnit|null}> keyed by task object id */
    private array $running = [];

    public function __construct(private readonly Container $container) {}

    /**
     * Resolved per event so Telemetry::fake() swaps take effect.
     */
    private function telemetry(): TelemetryManager
    {
        return $this->container->make(TelemetryManager::class);
    }

    private function native(): NativeProfiler
    {
        return $this->container->make(NativeProfiler::class);
    }

    private bool $monitor = true;

    public function register(Dispatcher $events, bool $monitor = true): void
    {
        $this->monitor = $monitor;

        if ($monitor) {
            $events->listen(CommandStarting::class, $this->commandStarting(...));
        }

        $events->listen(ScheduledTaskStarting::class, $this->taskStarting(...));
        $events->listen(ScheduledTaskFinished::class, $this->taskFinished(...));
        $events->listen(ScheduledTaskFailed::class, $this->taskFailed(...));
        $events->listen(ScheduledTaskSkipped::class, $this->taskSkipped(...));

        if (class_exists(ScheduledBackgroundTaskFinished::class)) {
            $events->listen(ScheduledBackgroundTaskFinished::class, $this->backgroundTaskFinished(...));
        }
    }

    /**
     * A `runInBackground()` task, reported by the parent process when the
     * child exits.
     *
     * The spans above skip these deliberately: the work happens in
     * another process, so there is no duration here to measure and no
     * context to attach it to. But whether it SUCCEEDED is knowable —
     * it is the child's exit code — and until now a background task
     * failing every night produced no signal at all. A counter, no
     * invented timing.
     */
    private function backgroundTaskFinished(ScheduledBackgroundTaskFinished $event): void
    {
        FailSafe::guard(function () use ($event) {
            $exit = $event->task->exitCode;

            $this->markFinished($this->taskName($event->task), $exit === 0);
            $outcome = $exit === null ? 'unknown' : ($exit === 0 ? 'processed' : 'failed');

            $this->telemetry()
                ->counter("schedule.background_tasks.{$outcome}", 'Background scheduled task runs by outcome')
                ->inc(1, [
                    'task' => $this->taskName($event->task),
                    'schedule.task.exit_code' => (string) ($exit ?? 'unknown'),
                ]);
        });
    }

    private function taskStarting(ScheduledTaskStarting $event): void
    {
        // Before the background early-return: a background task starts
        // here too, and it is due like any other.
        FailSafe::guard(fn () => $this->markStarted($event->task));

        if ($event->task->runInBackground) {
            return;
        }

        FailSafe::guard(function () use ($event) {
            $telemetry = $this->telemetry();
            $telemetry->resetContext();

            $name = $this->taskName($event->task);

            $span = $telemetry->tracer()->startSpan("schedule {$name}", attributes: [
                'schedule.task' => $name,
                'schedule.cron' => $event->task->expression,
                'schedule.timezone' => match (true) {
                    $event->task->timezone instanceof DateTimeZone => $event->task->timezone->getName(),
                    is_string($event->task->timezone) => $event->task->timezone,
                    default => Cast::string(config('app.timezone'), 'UTC'),
                },
                'schedule.without_overlapping' => $event->task->withoutOverlapping,
                'schedule.on_one_server' => $event->task->onOneServer,
            ]);

            $telemetry->publishTraceContext();

            $this->running[spl_object_id($event->task)] = [
                'span' => $span,
                'usage' => config('telemetry.instrument.resources', true) ? ResourceUsage::start() : null,
                'name' => $name,
                // schedule:run is in telemetry.native.exclude_commands
                // precisely so the task, not the scheduler, is the unit.
                // A task that shells out to its own artisan process burns
                // no CPU here and simply reports no profile.
                'unit' => $this->native()->begin('schedule', $span),
            ];
        });
    }

    private function taskFinished(ScheduledTaskFinished $event): void
    {
        FailSafe::guard(fn () => $this->completeTask(spl_object_id($event->task), 'processed'));
    }

    private function taskFailed(ScheduledTaskFailed $event): void
    {
        $id = spl_object_id($event->task);

        FailSafe::guard(function () use ($id, $event) {
            if (isset($this->running[$id])) {
                $this->running[$id]['span']->recordException($event->exception);
            }
        });

        $this->completeTask($id, 'failed');
    }

    private function taskSkipped(ScheduledTaskSkipped $event): void
    {
        FailSafe::guard(function () use ($event) {
            $this->telemetry()
                ->counter('schedule.tasks.skipped', 'Scheduled task runs skipped by filters or overlap locks')
                ->inc(1, ['task' => $this->taskName($event->task)]);

            // A skip is the scheduler deciding, not the scheduler missing it.
            if ($this->monitor) {
                $this->seen($this->taskName($event->task));
            }
        });

        FailSafe::guard(fn () => $this->telemetry()->flush());
    }

    private function completeTask(int $id, string $outcome): void
    {
        $running = $this->running[$id] ?? null;
        unset($this->running[$id]);

        FailSafe::guard(function () use ($running, $outcome) {
            if ($running === null) {
                return;
            }

            $span = $running['span'];
            $labels = ['task' => $running['name']];

            if ($span->status() === SpanStatus::Unset) {
                $span->setStatus($outcome === 'processed' ? SpanStatus::Ok : SpanStatus::Error);
            }

            if ($running['usage'] !== null) {
                $measured = $running['usage']->measure();

                $span->setAttributes(array_filter([
                    'php.memory.peak_bytes' => $measured['memoryPeakBytes'],
                    'php.cpu.time_ms' => $measured['cpuTimeMs'],
                    'process.memory.rss_peak_bytes' => $measured['rssPeakBytes'],
                    'process.cpu.utilization' => $measured['cpuUtilization'],
                ], static fn ($value) => $value !== null));
            }

            // Before end() — see the ordering note in TraceRequest.
            if ($running['unit'] !== null) {
                $result = $running['unit']->finish($this->telemetry()->tracer()->currentlySampled($span));

                if ($result !== null) {
                    NativeReporter::report($this->telemetry(), $result, $span, ['schedule.task' => $running['name']]);
                }
            }

            $span->end();

            $this->telemetry()
                // A scheduled task runs for minutes as a matter of course; the
                // default ladder tops out at ten seconds and would report
                // every one of them as exactly that.
                ->histogram('schedule.task.duration', buckets: [0.01, 0.05, 0.1, 0.5, 1, 5, 10, 30, 60, 300, 600, 1800], description: 'Scheduled task run duration', unit: 's')
                ->record($span->durationMs() / 1000, $labels);

            $this->telemetry()
                ->counter("schedule.tasks.{$outcome}", 'Scheduled task runs by outcome')
                ->inc(1, $labels);

            $this->markFinished($running['name'], $outcome === 'processed');
        });

        // Whatever happened above — including a throw the guard swallowed
        // before the unit was finished — the unit closes here. `schedule:run`
        // runs its tasks in one process, so a unit left open would refuse
        // every task after this one.
        if ($running !== null && $running['unit'] !== null) {
            $unit = $running['unit'];

            FailSafe::guard(static fn () => $unit->discard());
        }

        // Isolate each task: flush its telemetry and clear trace context
        // before the next task runs in the same process.
        //
        // Guarded, because `schedule:run` runs every task in ONE
        // process: a throw here does not cost this task's telemetry,
        // it costs every task after it in tonight's run.
        FailSafe::guard(fn () => $this->telemetry()->flush());
        FailSafe::guard(fn () => $this->telemetry()->resetContext());
    }

    /**
     * `schedule:run` is starting: publish when each task was last due.
     */
    private function commandStarting(CommandStarting $event): void
    {
        if ($event->command !== 'schedule:run') {
            return;
        }

        FailSafe::guard(function () {
            $telemetry = $this->telemetry();
            $now = time();
            $app = $this->container->make(Application::class);
            $down = $app->isDownForMaintenance();

            $telemetry->gauge('schedule.run.last_seen', description: 'When schedule:run last started (unix time)', unit: 's')->set($now);

            /** @var array<string, int> $due */
            $due = [];

            foreach ($this->container->make(Schedule::class)->events() as $task) {
                // Not this environment's task, or maintenance mode is holding
                // it: not due here, so it cannot be missed here.
                if (! $task->runsInEnvironment($app->environment()) || ($down && ! $task->runsInMaintenanceMode())) {
                    continue;
                }

                $previous = $this->previousDue($task, $now);

                if ($previous !== null) {
                    $name = $this->taskName($task);
                    // Tasks sharing a (bounded) name share a series: the
                    // latest due time is the one that has to be met.
                    $due[$name] = max($due[$name] ?? 0, $previous);
                }
            }

            foreach ($due as $name => $previous) {
                $labels = ['task' => $name];

                $telemetry->gauge('schedule.task.previous_due', description: 'When cron last said the task was due (unix time)', unit: 's')->set($previous, $labels);
                // When this task was last part of the schedule — a task
                // deleted from the schedule stops being refreshed, so the
                // alert rules can tell it from a task that stopped running.
                $telemetry->gauge('schedule.task.listed', description: 'When schedule:run last listed the task (unix time)', unit: 's')->set($now, $labels);
            }

            $telemetry->flush();
        });
    }

    private function markStarted(object $task): void
    {
        if (! $this->monitor) {
            return;
        }

        $name = $this->taskName($task);
        $now = microtime(true);

        $this->seen($name);

        $this->telemetry()
            ->gauge('schedule.task.running_since', description: 'When the current run of the task started, 0 when none is running (unix time)', unit: 's')
            ->set($now, ['task' => $name]);

        // How late it started against its own cron — a scheduler whose
        // earlier tasks run long starts the later ones late, and nothing
        // else shows that until they start missing.
        $previous = $this->previousDue($task, (int) $now);

        if ($previous !== null && ($delay = $now - $previous) >= 0 && $delay < 3600) {
            $this->telemetry()
                ->histogram('schedule.task.start_delay', buckets: [0.5, 1, 5, 15, 30, 60, 120, 300, 600, 1800], description: 'Time from a task being due to it starting', unit: 's')
                ->record($delay, ['task' => $name]);
        }
    }

    private function markFinished(string $name, bool $succeeded): void
    {
        if (! $this->monitor) {
            return;
        }

        $labels = ['task' => $name];

        $this->telemetry()
            ->gauge('schedule.task.running_since', description: 'When the current run of the task started, 0 when none is running (unix time)', unit: 's')
            ->set(0, $labels);

        if ($succeeded) {
            $this->telemetry()
                ->gauge('schedule.task.last_succeeded', description: 'When the task last finished successfully (unix time)', unit: 's')
                ->set(microtime(true), $labels);
        }
    }

    private function seen(string $name): void
    {
        $this->telemetry()
            ->gauge('schedule.task.last_seen', description: 'When the task last started or was skipped (unix time)', unit: 's')
            ->set(microtime(true), ['task' => $name]);
    }

    /**
     * The most recent time at or before $now the task's cron matched, in
     * the task's own timezone. Null for anything that is not a cron
     * expression the parser accepts.
     */
    private function previousDue(object $task, int $now): ?int
    {
        $expression = $task->expression ?? null;

        if (! is_string($expression) || ! CronExpression::isValidExpression($expression)) {
            return null;
        }

        $timezone = $task->timezone ?? null;
        $timezone = match (true) {
            $timezone instanceof DateTimeZone => $timezone->getName(),
            is_string($timezone) && $timezone !== '' => $timezone,
            default => Cast::string(config('app.timezone'), 'UTC'),
        };

        return (new CronExpression($expression))
            ->getPreviousRunDate(new DateTimeImmutable('@'.$now), 0, true, $timezone)
            ->getTimestamp();
    }

    /**
     * A BOUNDED name for the task — this is a metric label.
     *
     * getSummaryForDisplay() returns the description if one is set, and
     * otherwise the whole built command line: every argument, plus the output
     * redirection, plus a wrapping subshell for a background task. The old code
     * stripped the leading quoted binary and kept the rest, so the label
     * carried the arguments — and the arguments are exactly what varies.
     *
     * `$schedule->command('reports:send --date='.now()->toDateString())` minted
     * a new label value EVERY DAY; the per-tenant pattern
     * `foreach ($tenants as $t) { $schedule->command("tenant:sync {$t->id}") }`
     * minted one per tenant. Each is 15 histogram series plus three counters,
     * kept forever — a scheduler over 10 000 tenants produced 180 000 series
     * from one file, with label values hundreds of bytes long.
     *
     * An explicit description wins, because the app chose it and it is
     * therefore the app's own cardinality. Otherwise the label is the artisan
     * command NAME with its arguments dropped, which is bounded by the number
     * of commands that exist.
     *
     * The description escape hatch is a real one: `Schedule::job()` sets the
     * description from the job's `displayName()` without the app asking, so a
     * job that names itself per tenant is unbounded here too. Nothing this
     * class can do distinguishes that from a description deliberately chosen
     * to be fine-grained — override `displayName()` or call `->name('…')` with
     * a bounded label if a scheduled job varies its own name.
     */
    private function taskName(object $task): string
    {
        $description = $task->description ?? null;

        if (is_string($description) && $description !== '') {
            return $description;
        }

        if (! method_exists($task, 'getSummaryForDisplay')) {
            return 'closure';
        }

        $summary = (string) $task->getSummaryForDisplay();

        // runInBackground() wraps the command in a subshell —
        // `('php' 'artisan' inspire > … ; … schedule:finish …) > … &` — and
        // the opening parenthesis hid the artisan prefix below, so every
        // background task was labelled `exec`.
        if (str_starts_with($summary, '(')) {
            $summary = substr($summary, 1);
        }

        // Strip EXACTLY the two tokens Schedule::command() puts in front — the
        // quoted php binary and the quoted artisan path — and nothing else.
        // A greedy run of quoted tokens ate the command name too whenever the
        // app quoted it itself (`command("'tenant:sync' 48213")`), promoting
        // the ARGUMENT to the label: the unbounded case this exists to close.
        $stripped = 0;
        $summary = (string) preg_replace("/^('[^']*'\s+)?'artisan'\s+/", '', $summary, 1, $stripped);

        // Whether those tokens were there is what separates Schedule::command()
        // from Schedule::exec(), whose summary is an arbitrary shell line.
        $isArtisan = $stripped === 1;

        // Then the shell redirection: whitespace + an optional fd + '>', NOT
        // any '2' — a `[>2]` class turned `reports:v2:send` into `reports:v`,
        // silently merging two different commands into one series.
        $summary = (string) preg_replace('/\s+\d?>.*$/', '', $summary);
        $summary = trim($summary);

        // Any whitespace separates the name from its arguments, not just a
        // space: a tab-separated command line kept its arguments in the label.
        $name = preg_split('/\s+/', $summary, 2)[0] ?? '';
        $name = trim($name, "'\"");

        // Only an ARTISAN command name is safe to use. Without that check,
        // stripping a leading quoted token promotes the ARGUMENT:
        // `'/usr/bin/printf' alice` would have labelled the series `alice`,
        // and a varying argument is exactly the unbounded case being closed.
        // exec() tasks collapse to one bucket; give one a description if you
        // want it apart.
        if (! $isArtisan || $name === '') {
            return $summary === '' ? 'closure' : 'exec';
        }

        return $name;
    }
}
