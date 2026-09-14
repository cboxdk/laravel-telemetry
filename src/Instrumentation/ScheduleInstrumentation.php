<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\Cast;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\Support\ResourceUsage;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanStatus;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;

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
 */
final class ScheduleInstrumentation
{
    /** @var array<int, array{span: Span, usage: ResourceUsage|null, name: string}> keyed by task object id */
    private array $running = [];

    public function __construct(private readonly Container $container) {}

    /**
     * Resolved per event so Telemetry::fake() swaps take effect.
     */
    private function telemetry(): TelemetryManager
    {
        return $this->container->make(TelemetryManager::class);
    }

    public function register(Dispatcher $events): void
    {
        $events->listen(ScheduledTaskStarting::class, $this->taskStarting(...));
        $events->listen(ScheduledTaskFinished::class, $this->taskFinished(...));
        $events->listen(ScheduledTaskFailed::class, $this->taskFailed(...));
        $events->listen(ScheduledTaskSkipped::class, $this->taskSkipped(...));
    }

    private function taskStarting(ScheduledTaskStarting $event): void
    {
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
                    $event->task->timezone instanceof \DateTimeZone => $event->task->timezone->getName(),
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
            ];
        });
    }

    private function taskFinished(ScheduledTaskFinished $event): void
    {
        $this->completeTask(spl_object_id($event->task), 'processed');
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
        });

        $this->telemetry()->flush();
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

            $span->end();

            $this->telemetry()
                ->histogram('schedule.task.duration', description: 'Scheduled task run duration', unit: 'ms')
                ->record($span->durationMs(), $labels);

            $this->telemetry()
                ->counter("schedule.tasks.{$outcome}", 'Scheduled task runs by outcome')
                ->inc(1, $labels);
        });

        // Isolate each task: flush its telemetry and clear trace context
        // before the next task runs in the same process.
        $this->telemetry()->flush();
        $this->telemetry()->resetContext();
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

        // Schedule::command() always quotes the artisan path; exec() does not.
        $isArtisan = preg_match("/^'[^']*' 'artisan' /", $summary) === 1
            || str_starts_with($summary, "'artisan' ");

        // Drop the quoted binary/artisan path, then the shell redirection.
        // The redirection is whitespace + an optional fd + '>', NOT any '2':
        // a `[>2]` class turned `reports:v2:send` into `reports:v`, silently
        // merging two different commands into one series.
        $summary = (string) preg_replace("/^('[^']*' )+/", '', $summary);
        $summary = (string) preg_replace('/\s+\d?>.*$/', '', $summary);
        $summary = trim($summary);

        $name = $summary === '' ? false : strtok($summary, ' ');

        // Only an ARTISAN command name is safe to use. Schedule::command()
        // always builds its summary as "'<php>' 'artisan' <name> <args>", so
        // the quoted artisan token is what distinguishes it from
        // Schedule::exec(), whose summary is an arbitrary shell line. Without
        // that check, stripping the quoted binary promotes the ARGUMENT:
        // `'/usr/bin/printf' alice` would have labelled the series `alice`,
        // and a varying argument is exactly the unbounded case being closed.
        // exec() tasks collapse to one bucket; give one a description if you
        // want it apart.
        if (! $isArtisan || ! is_string($name)) {
            return $summary === '' ? 'closure' : 'exec';
        }

        return $name;
    }
}
