<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\DatabaseBusy;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Foundation\Events\MaintenanceModeDisabled;
use Illuminate\Foundation\Events\MaintenanceModeEnabled;

/**
 * The things that happen to an application rather than inside a request:
 * the schema changing, the door being shut, the connection pool filling up.
 *
 * None of these is a hot path — between them they fire a handful of times
 * a day — but they are the events that explain the ones that do. An error
 * rate that steps up at 14:32 is a mystery until a line on the same chart
 * says a migration finished at 14:32, and half an hour of 503s reads as an
 * outage until it reads as a maintenance window. Recording them costs
 * nothing and is the difference between a chart that raises a question and
 * a chart that answers it.
 */
final class LifecycleInstrumentation
{
    /**
     * Monotonic start per migration name, so the duration is real.
     * `hrtime(true)` is an int on 64-bit and a float on 32-bit builds.
     *
     * @var array<string, float|int>
     */
    private array $started = [];

    public function __construct(private readonly Container $container) {}

    public function register(Dispatcher $events, bool $migrations = true, bool $maintenance = true, bool $pool = true): void
    {
        if ($migrations) {
            $events->listen(MigrationsStarted::class, $this->migrationsStarted(...));
            $events->listen(MigrationStarted::class, $this->migrationStarted(...));
            $events->listen(MigrationEnded::class, $this->migrationEnded(...));
            $events->listen(MigrationsEnded::class, $this->migrationsEnded(...));
        }

        if ($maintenance) {
            $events->listen(MaintenanceModeEnabled::class, fn () => $this->maintenance(true));
            $events->listen(MaintenanceModeDisabled::class, fn () => $this->maintenance(false));
        }

        if ($pool) {
            $events->listen(DatabaseBusy::class, $this->databaseBusy(...));
        }
    }

    private function migrationsStarted(MigrationsStarted $event): void
    {
        FailSafe::guard(fn () => $this->telemetry()->event('db.migrations.started', [
            'db.migration.method' => (string) $event->method,
        ]));
    }

    private function migrationStarted(MigrationStarted $event): void
    {
        $this->started[$this->name($event->migration::class, $event->name)] = hrtime(true);
    }

    private function migrationEnded(MigrationEnded $event): void
    {
        FailSafe::guard(function () use ($event) {
            $name = $this->name($event->migration::class, $event->name);
            $start = $this->started[$name] ?? null;
            unset($this->started[$name]);

            $telemetry = $this->telemetry();
            $method = (string) $event->method;

            // A migration that takes four minutes locks a table for four
            // minutes. Which one, and for how long, is the whole question
            // during a deploy that went quiet.
            if ($start !== null) {
                $telemetry
                    ->histogram(
                        'db.migration.duration',
                        buckets: [0.01, 0.05, 0.1, 0.5, 1, 5, 10, 30, 60, 300],
                        description: 'Time to run one migration',
                        unit: 's',
                    )
                    ->record((hrtime(true) - $start) / 1_000_000_000, [
                        'db.migration.name' => $name,
                        'db.migration.method' => $method,
                    ]);
            }

            $telemetry->event('db.migration.ran', [
                'db.migration.name' => $name,
                'db.migration.method' => $method,
            ]);
        });
    }

    private function migrationsEnded(MigrationsEnded $event): void
    {
        // Anything still open here never ended — a migration that threw.
        // Dropping it keeps a long-running worker's map from growing, and
        // the failure itself is already an exception somebody sees.
        $this->started = [];

        FailSafe::guard(fn () => $this->telemetry()->event('db.migrations.finished', [
            'db.migration.method' => (string) $event->method,
        ]));
    }

    private function maintenance(bool $enabled): void
    {
        FailSafe::guard(fn () => $this->telemetry()->event('app.maintenance_mode', [
            'app.maintenance_mode.enabled' => $enabled,
        ]));
    }

    /**
     * The pool is full. Laravel raises this from a scheduled check, not
     * from the query path, so it is rare by construction — but it is the
     * one signal that separates "the database is slow" from "we ran out of
     * connections to ask it with", and those have opposite fixes.
     */
    private function databaseBusy(DatabaseBusy $event): void
    {
        FailSafe::guard(function () use ($event) {
            $telemetry = $this->telemetry();
            $connection = (string) $event->connectionName;

            $telemetry->counter('db.connections.busy', 'Times a connection pool was reported busy')
                ->inc(1, ['db.client.connection.pool.name' => $connection]);

            $telemetry->event('db.connections.busy', [
                'db.client.connection.pool.name' => $connection,
                'db.client.connection.count' => (int) $event->connections,
            ]);
        });
    }

    private function name(string $class, ?string $file): string
    {
        $name = $file !== null && $file !== '' ? $file : $class;

        // Anonymous migration classes carry the file they were declared in
        // after a null byte; the path is the only readable part.
        return basename(explode("\0", $name)[0], '.php');
    }

    private function telemetry(): TelemetryManager
    {
        return $this->container->make(TelemetryManager::class);
    }
}
