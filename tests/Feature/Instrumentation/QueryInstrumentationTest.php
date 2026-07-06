<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Instrumentation\QueryInstrumentation;
use Cbox\Telemetry\Testing\CollectingExporter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);

    // An isolated dispatcher, not the shared app('events') instance —
    // TelemetryServiceProvider already registered its own
    // QueryInstrumentation listener (with the package defaults) on the
    // app dispatcher at boot; reusing it here would double-fire every
    // assertion. This gives each test full control over the threshold.
    $this->events = new Dispatcher;
});

function registerQueryDuplicates(Dispatcher $events, bool $detectDuplicates = true, int $threshold = 3): void
{
    (new QueryInstrumentation(app()))->register($events, detectDuplicates: $detectDuplicates, duplicateThreshold: $threshold);
}

function runQuery(Dispatcher $events, string $sql = 'select * from "users" where "id" = ?'): void
{
    $connection = app('db')->connection();

    $events->dispatch(new QueryExecuted($sql, [1], 1.0, $connection));
}

it('flags a query that repeats past the threshold, exactly once', function () {
    registerQueryDuplicates($this->events);

    Telemetry::span('request', function () {
        runQuery($this->events);
        runQuery($this->events);
        runQuery($this->events); // 3rd — crosses the default threshold
        runQuery($this->events); // 4th — should NOT fire again
        runQuery($this->events); // 5th — should NOT fire again
    });

    Telemetry::flush();

    $families = collect(Telemetry::collect())->keyBy(fn ($f) => $f->name());

    expect($families['db.queries.duplicated']->samples[0]->value)->toBe(1.0)
        ->and($families['db.queries.duplicated']->samples[0]->labels['connection'])->not->toBeEmpty();

    $events = collect($this->collector->batches())->flatMap(fn ($b) => $b->events);
    $duplicateEvents = $events->where('name', 'db.query.duplicate_detected');

    expect($duplicateEvents)->toHaveCount(1);

    $event = $duplicateEvents->first();
    expect($event->attributes['db.query.repeat_count'])->toBe(3)
        ->and($event->attributes['db.query.text'])->toContain('select * from "users"');
});

it('does not flag distinct queries under the threshold', function () {
    registerQueryDuplicates($this->events);

    Telemetry::span('request', function () {
        runQuery($this->events, 'select * from "users" where "id" = ?');
        runQuery($this->events, 'select * from "posts" where "id" = ?');
        runQuery($this->events, 'select * from "users" where "id" = ?'); // only 2 repeats
    });

    Telemetry::flush();

    $families = collect(Telemetry::collect())->keyBy(fn ($f) => $f->name());

    expect($families->has('db.queries.duplicated'))->toBeFalse();
});

it('tallies the duplicate count on the root span', function () {
    registerQueryDuplicates($this->events);

    Telemetry::span('request', function () {
        runQuery($this->events);
        runQuery($this->events);
        runQuery($this->events);
    });

    Telemetry::flush();

    $span = collect($this->collector->batches())->flatMap(fn ($b) => $b->spans)->first(fn ($s) => $s->parentSpanId === null);

    expect($span->attributes()['db.query.duplicate.count'])->toBe(1);
});

it('resets duplicate tracking between separate traces', function () {
    registerQueryDuplicates($this->events);

    Telemetry::span('request-one', function () {
        runQuery($this->events);
        runQuery($this->events);
        runQuery($this->events);
    });

    Telemetry::resetContext();

    Telemetry::span('request-two', function () {
        runQuery($this->events);
        runQuery($this->events);
    });

    Telemetry::flush();

    // Only the first trace's 3 repeats crossed the threshold.
    $families = collect(Telemetry::collect())->keyBy(fn ($f) => $f->name());

    expect($families['db.queries.duplicated']->samples[0]->value)->toBe(1.0);
});

it('can be disabled', function () {
    registerQueryDuplicates($this->events, detectDuplicates: false);

    Telemetry::span('request', function () {
        runQuery($this->events);
        runQuery($this->events);
        runQuery($this->events);
    });

    Telemetry::flush();

    $families = collect(Telemetry::collect())->keyBy(fn ($f) => $f->name());

    expect($families->has('db.queries.duplicated'))->toBeFalse();
});

it('respects a custom threshold', function () {
    registerQueryDuplicates($this->events, threshold: 2);

    Telemetry::span('request', function () {
        runQuery($this->events);
        runQuery($this->events);
    });

    Telemetry::flush();

    $families = collect(Telemetry::collect())->keyBy(fn ($f) => $f->name());

    expect($families['db.queries.duplicated']->samples[0]->value)->toBe(1.0);
});
