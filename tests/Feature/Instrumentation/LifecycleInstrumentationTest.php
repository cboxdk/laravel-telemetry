<?php

declare(strict_types=1);

use Cbox\Telemetry\Events\TelemetryEvent;
use Cbox\Telemetry\Facades\Telemetry;
use Illuminate\Database\Events\DatabaseBusy;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Events\MaintenanceModeDisabled;
use Illuminate\Foundation\Events\MaintenanceModeEnabled;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Telemetry::fake();
    // From a file named like a real migration: Laravel 13 names the
    // migration on the event, 12 only through the file its anonymous
    // class was declared in, and both have to come out the same.
    $this->migration = require __DIR__.'/../../fixtures/migrations/2026_09_26_000000_add_index.php';
});

it('times a migration and annotates that it ran', function () {
    Event::dispatch(new MigrationsStarted('up'));
    Event::dispatch(new MigrationStarted($this->migration, 'up', '2026_09_26_000000_add_index.php'));
    Event::dispatch(new MigrationEnded($this->migration, 'up', '2026_09_26_000000_add_index.php'));
    Event::dispatch(new MigrationsEnded('up'));

    Telemetry::assertHistogramRecorded('db.migration.duration', [
        'db.migration.name' => '2026_09_26_000000_add_index',
        'db.migration.method' => 'up',
    ]);

    Telemetry::assertEventEmitted('db.migrations.started');
    Telemetry::assertEventEmitted('db.migration.ran');
    Telemetry::assertEventEmitted('db.migrations.finished');
});

it('annotates a migration that ran without inventing a duration for it', function () {
    // MigrationEnded without a matching MigrationStarted (a partial run
    // resumed, or a listener registered mid-flight). The run is still
    // worth annotating; a duration measured from a start we never saw
    // would be a fabricated number.
    Event::dispatch(new MigrationEnded($this->migration, 'up', '2026_09_26_000000_add_index.php'));

    Telemetry::assertEventEmitted('db.migration.ran', fn (TelemetryEvent $e): bool => $e->attributes['db.migration.name'] === '2026_09_26_000000_add_index');
    Telemetry::assertHistogramNotRecorded('db.migration.duration');
});

it('names a migration from its file when the event carries no name', function () {
    // Laravel 12's migration events have no `name`. Reading it threw
    // inside the guard, so no migration was recorded there at all — and
    // the fallback named every anonymous migration `Migration@anonymous`.
    Event::dispatch(new MigrationStarted($this->migration, 'up'));
    Event::dispatch(new MigrationEnded($this->migration, 'up'));

    Telemetry::assertHistogramRecorded('db.migration.duration', [
        'db.migration.name' => '2026_09_26_000000_add_index',
        'db.migration.method' => 'up',
    ]);
});

it('annotates both edges of a maintenance window', function () {
    Event::dispatch(new MaintenanceModeEnabled);
    Event::dispatch(new MaintenanceModeDisabled);

    Telemetry::assertEventEmitted('app.maintenance_mode', fn (TelemetryEvent $e): bool => $e->attributes['app.maintenance_mode.enabled'] === true);
    Telemetry::assertEventEmitted('app.maintenance_mode', fn (TelemetryEvent $e): bool => $e->attributes['app.maintenance_mode.enabled'] === false);
});

it('records a connection count over the operator threshold', function () {
    Event::dispatch(new DatabaseBusy('mysql', 140));

    Telemetry::assertCounterIncremented('db.connections.over_threshold', [
        'laravel.db.connection' => 'mysql',
    ]);
    Telemetry::assertEventEmitted(
        'db.connections.over_threshold',
        fn (TelemetryEvent $e): bool => $e->attributes['db.connections.observed'] === 140,
    );
});
