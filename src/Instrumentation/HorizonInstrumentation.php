<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\Cast;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Horizon\Events\JobsMigrated;
use Laravel\Horizon\Events\LongWaitDetected;
use Laravel\Horizon\Events\MasterSupervisorLooped;
use Laravel\Horizon\Events\MasterSupervisorOutOfMemory;
use Laravel\Horizon\Events\SupervisorLooped;
use Laravel\Horizon\Events\SupervisorOutOfMemory;
use Laravel\Horizon\Events\SupervisorProcessRestarting;
use Laravel\Horizon\Events\WorkerProcessRestarting;
use Laravel\Horizon\MasterSupervisor;

/**
 * Horizon operational visibility — everything a generic queue-event
 * listener can't see, because it happens inside the master/supervisor
 * loop, not a job.
 *
 * Job-level tracing (spans, durations, outcomes) already works: Horizon
 * workers still fire the standard `Illuminate\Queue\Events\*` that
 * `QueueInstrumentation` listens to. This class deliberately does NOT
 * duplicate that with Horizon's own JobPushed/JobReserved/JobFailed —
 * those fire once per Redis operation (much higher volume) and would
 * just double-count the same jobs under a different name.
 *
 * Supervisor/master state (process count, paused) is read from the
 * `Looped` events — Horizon's own heartbeat, roughly once a second — and
 * PUSHED into the shared store (`.set()`), the same "worker self-reports
 * its own live state" pattern as `worker.memory.php` in
 * `QueueInstrumentation`. These are pull-shaped values by nature but
 * pushed on purpose: the master/supervisor process is long-running and
 * standalone, so nothing else could evaluate a callback for it later —
 * a separate `telemetry:flush` process has never seen this Supervisor
 * object and never will.
 */
final class HorizonInstrumentation
{
    public function __construct(private readonly Container $container) {}

    private function telemetry(): TelemetryManager
    {
        return $this->container->make(TelemetryManager::class);
    }

    public function register(Dispatcher $events): void
    {
        $events->listen(SupervisorLooped::class, $this->supervisorLooped(...));
        $events->listen(MasterSupervisorLooped::class, $this->masterLooped(...));
        $events->listen(LongWaitDetected::class, $this->longWaitDetected(...));
        $events->listen(WorkerProcessRestarting::class, fn () => $this->processRestarting('worker'));
        $events->listen(SupervisorProcessRestarting::class, fn () => $this->processRestarting('supervisor'));
        $events->listen(SupervisorOutOfMemory::class, fn () => $this->outOfMemory('supervisor'));
        $events->listen(MasterSupervisorOutOfMemory::class, fn () => $this->outOfMemory('master'));
        $events->listen(JobsMigrated::class, $this->jobsMigrated(...));
    }

    /**
     * Strip the per-process random token Horizon puts in these names.
     *
     * MasterSupervisor::name() is `basename()-<4 random chars>`, minted fresh
     * every time the master process STARTS, and ProvisioningPlan composes each
     * supervisor as "{master}:{supervisor}". Both therefore carried a value
     * that changes on every deploy — and these are gauges, frozen at their last
     * value with no TTL, so `sum(horizon_supervisor_processes)` grew
     * monotonically forever and the "how many workers do I have" panel became
     * fiction. Worse, `horizon.master.paused` stayed at 0 ("working") for every
     * dead master, so a `min(...) == 1` alert could never fire again once one
     * master had ever run.
     *
     * The token is removed by asking Horizon for the basename and cutting
     * exactly that prefix — NOT by matching four characters, which would eat
     * the last segment of a host legitimately named `queue-prod` or `web-node`.
     */
    private function withoutToken(string $name): string
    {
        $basename = FailSafe::guard(static fn (): string => MasterSupervisor::basename());

        if (! is_string($basename) || $basename === '' || ! str_starts_with($name, $basename.'-')) {
            return $name;
        }

        $rest = substr($name, strlen($basename) + 1);

        // What follows the token is either nothing, or ":supervisor".
        $colon = strpos($rest, ':');

        return $colon === false ? $basename : $basename.substr($rest, $colon);
    }

    private function supervisorLooped(SupervisorLooped $event): void
    {
        FailSafe::guard(function () use ($event) {
            $supervisor = $event->supervisor;
            $labels = [
                'supervisor' => $this->withoutToken(Cast::string($supervisor->name)),
                'connection' => Cast::string($supervisor->options->connection),
                'queue' => Cast::string($supervisor->options->queue),
            ];

            $this->telemetry()
                ->gauge('horizon.supervisor.processes', description: 'Active worker processes managed by this supervisor')
                ->set((float) $supervisor->totalProcessCount(), $labels);

            $this->telemetry()
                ->gauge('horizon.supervisor.paused', description: 'Whether this supervisor is currently paused (1) or working (0)')
                ->set($supervisor->working ? 0.0 : 1.0, ['supervisor' => $labels['supervisor']]);
        });
    }

    private function masterLooped(MasterSupervisorLooped $event): void
    {
        FailSafe::guard(function () use ($event) {
            $master = $event->master;
            $labels = ['master' => $this->withoutToken(Cast::string($master->name))];

            $this->telemetry()
                ->gauge('horizon.master.paused', description: 'Whether this master supervisor is currently paused (1) or working (0)')
                ->set($master->working ? 0.0 : 1.0, $labels);

            $this->telemetry()
                ->gauge('horizon.master.supervisors', description: 'Supervisors managed by this master supervisor')
                ->set((float) $master->supervisors->count(), $labels);
        });
    }

    /**
     * The signal ops actually needs: a queue's wait time crossed the
     * configured threshold. Rare enough to warrant a structured event
     * (OTLP log), not just a silent counter.
     */
    private function longWaitDetected(LongWaitDetected $event): void
    {
        FailSafe::guard(function () use ($event) {
            $labels = ['connection' => Cast::string($event->connection), 'queue' => Cast::string($event->queue)];

            $this->telemetry()
                ->counter('horizon.long_wait.detected', 'Times a queue exceeded its configured long-wait threshold')
                ->inc(1, $labels);

            $this->telemetry()->event('horizon.long_wait.detected', [...$labels, 'wait.seconds' => $event->seconds]);
        });

        $this->telemetry()->flush();
    }

    private function processRestarting(string $type): void
    {
        FailSafe::guard(function () use ($type) {
            $this->telemetry()
                ->counter('horizon.process.restarts', 'Worker/supervisor process restarts')
                ->inc(1, ['type' => $type]);
        });
    }

    private function outOfMemory(string $type): void
    {
        FailSafe::guard(function () use ($type) {
            $this->telemetry()
                ->counter('horizon.process.out_of_memory', 'Supervisor/master processes that exceeded their memory limit')
                ->inc(1, ['type' => $type]);

            $this->telemetry()->event('horizon.process.out_of_memory', ['type' => $type]);
        });

        $this->telemetry()->flush();
    }

    private function jobsMigrated(JobsMigrated $event): void
    {
        FailSafe::guard(function () use ($event) {
            $this->telemetry()
                ->counter('horizon.jobs.migrated', 'Jobs migrated between queues (e.g. rebalancing, retry migration)')
                ->inc($event->payloads->count(), [
                    'connection' => Cast::string($event->connectionName),
                    'queue' => Cast::string($event->queue),
                ]);
        });
    }
}
