<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Instrumentation\QueryInstrumentation;
use Cbox\Telemetry\Testing\CollectingExporter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Collection;

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

function registerQueryDuplicates(Dispatcher $events, bool $detectDuplicates = true, int $threshold = 3, string $origin = QueryInstrumentation::ORIGIN_FLAGGED): void
{
    (new QueryInstrumentation(app()))->register($events, detectDuplicates: $detectDuplicates, duplicateThreshold: $threshold, origin: $origin, basePath: dirname(__DIR__, 3));
}

function runQuery(Dispatcher $events, string $sql = 'select * from "users" where "id" = ?', array $bindings = [1], float $time = 1.0): void
{
    $connection = app('db')->connection();

    $events->dispatch(new QueryExecuted($sql, $bindings, $time, $connection));
}

function querySpans(CollectingExporter $collector): Collection
{
    return collect($collector->batches())->flatMap(fn ($b) => $b->spans)->where('name', 'db.query')->values();
}

it('counts every query on the db.queries counter with connection and driver', function () {
    registerQueryDuplicates($this->events);

    Telemetry::span('request', function () {
        runQuery($this->events);
        runQuery($this->events, 'select * from "orders"');
    });

    $families = collect(Telemetry::collect())->keyBy(fn ($f) => $f->name());

    expect($families['db.queries']->samples[0]->value)->toBe(2.0)
        ->and($families['db.queries']->samples[0]->labels['connection'])->not->toBeEmpty()
        ->and($families['db.queries']->samples[0]->labels['driver'])->not->toBeEmpty();
});

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

it('tells an N+1 (same shape, different values) from a duplicate', function () {
    registerQueryDuplicates($this->events);

    Telemetry::span('request', function () {
        foreach ([1, 2, 3, 4] as $id) {
            runQuery($this->events, 'select * from "posts" where "user_id" = ?', [$id]);
        }
    });

    Telemetry::flush();

    $families = collect(Telemetry::collect())->keyBy(fn ($f) => $f->name());
    $events = collect($this->collector->batches())->flatMap(fn ($b) => $b->events);

    expect($families->has('db.queries.duplicated'))->toBeFalse()
        ->and($families['db.queries.n_plus_one']->samples[0]->value)->toBe(1.0)
        ->and($events->where('name', 'db.query.duplicate_detected'))->toHaveCount(0);

    $nPlusOne = $events->where('name', 'db.query.n_plus_one_detected');

    expect($nPlusOne)->toHaveCount(1)
        ->and($nPlusOne->first()->attributes['db.query.repeat_count'])->toBe(3)
        ->and($nPlusOne->first()->attributes['laravel.db.query.shape'])->toHaveLength(16)
        ->and($nPlusOne->first()->attributes['code.file.path'])->toBe('tests/Feature/Instrumentation/QueryInstrumentationTest.php');

    $root = collect($this->collector->batches())->flatMap(fn ($b) => $b->spans)->first(fn ($s) => $s->parentSpanId === null);

    expect($root->attributes()['db.query.n_plus_one.count'])->toBe(1)
        ->and($root->attributes())->not->toHaveKey('db.query.duplicate.count');
});

it('groups whereIn lists of different lengths into one N+1 shape', function () {
    registerQueryDuplicates($this->events);

    Telemetry::span('request', function () {
        runQuery($this->events, 'select * from "tags" where "post_id" in (?, ?)', [1, 2]);
        runQuery($this->events, 'select * from "tags" where "post_id" in (?, ?, ?)', [3, 4, 5]);
        runQuery($this->events, 'select * from "tags" where "post_id" in (?)', [6]);
    });

    Telemetry::flush();

    $events = collect($this->collector->batches())->flatMap(fn ($b) => $b->events);

    expect($events->where('name', 'db.query.n_plus_one_detected'))->toHaveCount(1);
});

it('does not call a loop of writes an N+1', function () {
    registerQueryDuplicates($this->events);

    Telemetry::span('request', function () {
        foreach ([1, 2, 3, 4] as $id) {
            runQuery($this->events, 'insert into "logs" ("id") values (?)', [$id]);
        }
    });

    Telemetry::flush();

    $events = collect($this->collector->batches())->flatMap(fn ($b) => $b->events);

    expect($events->where('name', 'db.query.n_plus_one_detected'))->toHaveCount(0);
});

it('treats equal values of different types as different bindings', function () {
    registerQueryDuplicates($this->events);

    Telemetry::span('request', function () {
        runQuery($this->events, 'select * from "t" where "a" = ?', ['1']);
        runQuery($this->events, 'select * from "t" where "a" = ?', [1]);
        runQuery($this->events, 'select * from "t" where "a" = ?', [true]);
    });

    Telemetry::flush();

    $events = collect($this->collector->batches())->flatMap(fn ($b) => $b->events);

    expect($events->where('name', 'db.query.duplicate_detected'))->toHaveCount(0);
});

it('marks repeated query spans and points them at the calling line', function () {
    registerQueryDuplicates($this->events);

    Telemetry::span('request', function () {
        foreach ([1, 2, 3, 4] as $id) {
            runQuery($this->events, 'select * from "posts" where "user_id" = ?', [$id]);
        }
        runQuery($this->events, 'select * from "users"', []);
    });

    Telemetry::flush();

    $spans = querySpans($this->collector);
    $posts = $spans->filter(fn ($s) => str_contains($s->attributes()['db.query.text'], 'posts'))->values();
    $users = $spans->first(fn ($s) => str_contains($s->attributes()['db.query.text'], 'users'));

    expect($posts)->toHaveCount(4)
        ->and($posts->pluck(fn ($s) => $s->attributes()['laravel.db.query.shape'])->unique())->toHaveCount(1)
        ->and($posts[0]->attributes())->not->toHaveKey('laravel.db.query.repeat')
        ->and($posts[0]->attributes())->not->toHaveKey('code.file.path')
        ->and($posts[2]->attributes()['laravel.db.query.repeat'])->toBe('n_plus_one')
        ->and($posts[2]->attributes()['code.file.path'])->toBe('tests/Feature/Instrumentation/QueryInstrumentationTest.php')
        ->and($posts[2]->attributes()['code.line.number'])->toBeInt()
        ->and($posts[2]->attributes()['code.function.name'])->toBeString()
        ->and($posts[3]->attributes()['laravel.db.query.repeat'])->toBe('n_plus_one')
        ->and($posts[3]->attributes())->not->toHaveKey('code.file.path')
        ->and($users->attributes())->not->toHaveKey('code.file.path');
});

it('marks identical repeats as duplicates on the span', function () {
    registerQueryDuplicates($this->events);

    Telemetry::span('request', function () {
        runQuery($this->events);
        runQuery($this->events);
        runQuery($this->events);
    });

    Telemetry::flush();

    expect(querySpans($this->collector)->last()->attributes()['laravel.db.query.repeat'])->toBe('duplicate');
});

it('points slow queries at their origin', function () {
    registerQueryDuplicates($this->events);

    Telemetry::span('request', fn () => runQuery($this->events, time: 500.0));

    Telemetry::flush();

    expect(querySpans($this->collector)->first()->attributes()['code.file.path'])
        ->toBe('tests/Feature/Instrumentation/QueryInstrumentationTest.php');

    $root = collect($this->collector->batches())->flatMap(fn ($b) => $b->spans)->first(fn ($s) => $s->parentSpanId === null);

    expect($root->attributes()['db.query.slow.count'])->toBe(1);
});

it('attaches an origin to every query in all mode, and none when off', function (string $origin, bool $expected) {
    registerQueryDuplicates($this->events, origin: $origin);

    Telemetry::span('request', fn () => runQuery($this->events, 'select 1', [], 500.0));

    Telemetry::flush();

    expect(array_key_exists('code.file.path', querySpans($this->collector)->first()->attributes()))->toBe($expected);
})->with([
    'all' => [QueryInstrumentation::ORIGIN_ALL, true],
    'off' => [QueryInstrumentation::ORIGIN_OFF, false],
]);

it('reports what its own hooks cost on the root span', function () {
    registerQueryDuplicates($this->events);

    Telemetry::span('request', function () {
        foreach (range(1, 20) as $id) {
            runQuery($this->events, 'select * from "posts" where "user_id" = ?', [$id]);
        }
    });

    Telemetry::flush();

    $root = collect($this->collector->batches())->flatMap(fn ($b) => $b->spans)->first(fn ($s) => $s->parentSpanId === null);

    expect($root->attributes()['telemetry.overhead_ms'])->toBeGreaterThan(0);
});
