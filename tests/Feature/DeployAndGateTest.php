<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Support\ExportResult;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Testing\RejectingExporter;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);
});

it('emits a deployment marker event and counter', function () {
    $this->artisan('telemetry:deploy', ['--id' => 'v1.2.3', '--notes' => 'hotfix'])
        ->expectsOutputToContain('v1.2.3')
        ->assertSuccessful();

    $event = collect($this->collector->batches())->flatMap(fn ($batch) => $batch->events)
        ->firstWhere('name', 'app.deployment');

    expect($event->attributes['deployment.id'])->toBe('v1.2.3')
        ->and($event->attributes['deployment.notes'])->toBe('hotfix');

    expect(collect(Telemetry::collect())->keyBy(fn ($family) => $family->name()))
        ->toHaveKey('deployments');
});

it('fails the deploy step when the marker was rejected', function () {
    // A deploy pipeline that sees a green telemetry:deploy has been told
    // the annotation is in the backend.
    Telemetry::addExporter(new RejectingExporter(
        ExportResult::failed('HTTP 400: {"message":"unknown deployment attribute"}'),
        name: 'otlp',
    ));

    Artisan::call('telemetry:deploy', ['--id' => 'v1.2.3']);

    expect(Artisan::output())
        ->toContain('Deployment marker was rejected')
        ->toContain('HTTP 400');

    expect(Artisan::call('telemetry:deploy', ['--id' => 'v1.2.3']))->toBe(1);
});

it('counts gate checks by ability and outcome with root-span tallies', function () {
    Gate::define('view-reports', fn ($user) => $user->getAuthIdentifier() === 1);

    Telemetry::span('request-ish', function () {
        Gate::forUser(new GenericUser(['id' => 1]))->allows('view-reports');
        Gate::forUser(new GenericUser(['id' => 2]))->allows('view-reports');
    });

    Telemetry::flush();

    $samples = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name())['authorization.checks']->samples;
    $byResult = collect($samples)->keyBy(fn ($sample) => $sample->labels['result']);

    expect($byResult['allowed']->labels['ability'])->toBe('view-reports')
        ->and($byResult['allowed']->value)->toBe(1.0)
        ->and($byResult['denied']->value)->toBe(1.0);

    $root = collect($this->collector->batches())->flatMap(fn ($batch) => $batch->spans)
        ->first(fn ($span) => $span->parentSpanId === null);

    expect($root->attributes()['gate.check.count'])->toBe(2)
        ->and($root->attributes()['gate.denied.count'])->toBe(1);
});

it('exposes runtime and framework versions on the resource', function () {
    $resource = Telemetry::resource();

    expect($resource['process.runtime.name'])->toBe('php')
        ->and($resource['process.runtime.version'])->toBe(PHP_VERSION)
        ->and($resource['laravel.version'])->not->toBeEmpty();
});

it('counts policy method calls the same as gate closures', function () {
    $policy = new class
    {
        public function update($user, $model): bool
        {
            return false;
        }
    };

    Gate::policy(stdClass::class, $policy::class);
    app()->instance($policy::class, $policy);

    Telemetry::span('request-ish', function () {
        Gate::forUser(new GenericUser(['id' => 1]))->allows('update', new stdClass);
    });

    Telemetry::flush();

    $samples = collect(Telemetry::collect())->keyBy(fn ($f) => $f->name())['authorization.checks']->samples;

    expect(collect($samples)->firstWhere(fn ($s) => $s->labels['ability'] === 'update' && $s->labels['result'] === 'denied'))->not->toBeNull();
});
