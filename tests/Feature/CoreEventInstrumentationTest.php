<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Logging\TelemetryLogHandler;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Testing\CollectingExporter;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Monolog\Logger;

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);

    config()->set('database.default', 'testing');
    config()->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
});

function families(): Collection
{
    return collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());
}

it('counts auth lifecycle events with the guard', function () {
    $user = new GenericUser(['id' => 7]);

    event(new Login('admin', $user, false));
    event(new Failed('web', null, ['email' => 'x@example.com']));
    event(new Failed('web', null, ['email' => 'x@example.com']));
    event(new Lockout(request()));

    $samples = collect(families()['auth.events']->samples)->keyBy(fn ($sample) => $sample->labels['event']);

    expect($samples['login']->labels['guard'])->toBe('admin')
        ->and($samples['failed']->value)->toBe(2.0)
        ->and($samples['lockout']->value)->toBe(1.0);
});

it('wraps transactions in spans with outcome and nesting', function () {
    Telemetry::span('request-ish', function () {
        DB::transaction(function () {
            DB::select('select 1');

            DB::transaction(fn () => DB::select('select 2'));
        });

        try {
            DB::transaction(function () {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }
    });

    Telemetry::flush();

    $transactions = collect($this->collector->batches())->flatMap(fn ($batch) => $batch->spans)
        ->filter(fn ($span) => $span->name === 'db.transaction')->values();

    expect($transactions)->toHaveCount(3);

    $outer = $transactions->firstWhere(fn ($span) => $span->attributes()['db.transaction.depth'] === 0
        && $span->attributes()['db.transaction.outcome'] === 'committed');
    $nested = $transactions->firstWhere(fn ($span) => $span->attributes()['db.transaction.depth'] === 1);
    $rolledBack = $transactions->firstWhere(fn ($span) => $span->attributes()['db.transaction.outcome'] === 'rolled_back');

    expect($nested->parentSpanId)->toBe($outer->spanId)
        ->and($rolledBack)->not->toBeNull()
        ->and(families()['db.transactions.rolled_back']->samples[0]->value)->toBe(1.0);
});

it('tallies model hydrations and counts write events per model', function () {
    DB::statement('create table demo_items (id integer primary key autoincrement, name text)');

    $model = new class extends Model
    {
        protected $table = 'demo_items';

        protected $guarded = [];

        public $timestamps = false;
    };

    Telemetry::span('request-ish', function () use ($model) {
        $model::create(['name' => 'one']);
        $model::create(['name' => 'two']);
        $model::all();
    });

    Telemetry::flush();

    $root = collect($this->collector->batches())->flatMap(fn ($batch) => $batch->spans)
        ->first(fn ($span) => $span->parentSpanId === null);

    expect($root->attributes()['model.hydrations'])->toBe(2);

    $writes = collect(families()['models.events']->samples)->keyBy(fn ($sample) => $sample->labels['event']);

    expect($writes['created']->value)->toBe(2.0);
});

it('counts batch lifecycle and notification failures', function () {
    event(new NotificationFailed(
        new GenericUser(['id' => 1]),
        new class extends Notification {},
        'mail',
    ));

    expect(collect(families()['notifications.failed']->samples)->sum('value'))->toBe(1.0)
        ->and(families()['notifications.failed']->samples[0]->labels['channel'])->toBe('mail');
});

it('counts deprecations logged through the telemetry channel', function () {
    $handler = new TelemetryLogHandler(app(TelemetryManager::class));

    $logger = new Logger('deprecations', [$handler]);
    $logger->warning('str_contains(): Passing null to parameter #1 is deprecated');

    expect(families()['php.deprecations']->samples[0]->value)->toBe(1.0);
});

it('uses the class basename consistently on sent and failed notification counters', function () {
    $notification = new class extends Notification {};

    event(new NotificationSent(new GenericUser(['id' => 1]), $notification, 'mail'));
    event(new NotificationFailed(new GenericUser(['id' => 1]), $notification, 'mail'));

    $fams = families();
    $sentLabel = $fams['notifications.sent']->samples[0]->labels['notification'];
    $failedLabel = $fams['notifications.failed']->samples[0]->labels['notification'];

    // Both are the SAME shape (basename), joinable in dashboards, and never a FQCN.
    expect($sentLabel)->toBe($failedLabel)
        ->and($sentLabel)->not->toContain('\\');
});
