<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\DatabaseBusy;
use Illuminate\Database\Events\DatabaseRefreshed;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationSkipped;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\Events\SchemaLoaded;
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

            // A migration deliberately not run, a schema dump installed
            // instead of a migration history, and the destructive reset.
            // Each one changes the schema without leaving a
            // `db.migration.ran` behind, so "what changed at 14:32"
            // answers "nothing" for exactly the changes most likely to
            // have caused whatever happened at 14:32.
            $this->annotate($events, MigrationSkipped::class, 'db.migration.skipped', static fn (object $e): array => [
                'db.migration.name' => is_string($e->migrationName ?? null) ? $e->migrationName : 'unknown',
            ]);

            $this->annotate($events, SchemaLoaded::class, 'db.schema.loaded', static fn (object $e): array => [
                'laravel.db.connection' => is_string($e->connectionName ?? null) ? $e->connectionName : 'default',
            ]);

            $this->annotate($events, DatabaseRefreshed::class, 'db.refreshed', static fn (object $e): array => [
                'laravel.db.connection' => is_string($e->database ?? null) ? $e->database : 'default',
                'db.refresh.seeding' => (bool) ($e->seeding ?? false),
            ]);
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
     * The database reported more open connections than the operator said
     * to tolerate.
     *
     * Precisely that, and nothing more. Laravel raises this from
     * `db:monitor`, which compares the server's own thread count against
     * a `--max` somebody chose; it can fire at ten connections on a box
     * that would happily serve a thousand, and it does not mean any
     * checkout failed. So this is not `db.client.connection.*`, which is
     * about a client-side pool we do not have — it is a threshold
     * crossing on a count, named as one.
     *
     * Still worth recording: the count climbing is the difference between
     * "the database is slow" and "everyone is queuing to talk to it",
     * which have opposite fixes and identical symptoms.
     */
    private function databaseBusy(DatabaseBusy $event): void
    {
        FailSafe::guard(function () use ($event) {
            $telemetry = $this->telemetry();
            $connection = (string) $event->connectionName;

            $telemetry->counter('db.connections.over_threshold', 'Times db:monitor found more connections than its --max')
                ->inc(1, ['laravel.db.connection' => $connection]);

            $telemetry->event('db.connections.over_threshold', [
                'laravel.db.connection' => $connection,
                'db.connections.observed' => (int) $event->connections,
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

    /**
     * Emit an annotation for an event that may not exist on this Laravel
     * version. Listening for a missing class is harmless — nothing
     * dispatches it — but a typed closure parameter would not be.
     *
     * @param  Closure(object): array<string, scalar|null>  $attributes
     */
    private function annotate(Dispatcher $events, string $event, string $name, Closure $attributes): void
    {
        if (! class_exists($event)) {
            return;
        }

        $events->listen($event, function (object $fired) use ($name, $attributes) {
            FailSafe::guard(fn () => $this->telemetry()->event($name, $attributes($fired)));
        });
    }

    private function telemetry(): TelemetryManager
    {
        return $this->container->make(TelemetryManager::class);
    }
}
