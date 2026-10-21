<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Testing\CollectingExporter;
use Illuminate\Support\Collection;
use Laravel\Pennant\Events\FeatureRetrieved;
use Laravel\Pennant\Events\UnknownFeatureResolved;

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);
});

function pennantFamilies(): Collection
{
    return collect(Telemetry::collect())->keyBy(fn ($f) => $f->name());
}

it('counts boolean feature checks as active/inactive', function () {
    app('events')->dispatch(new FeatureRetrieved('new-dashboard', 1, true));
    app('events')->dispatch(new FeatureRetrieved('new-dashboard', 2, false));

    $samples = pennantFamilies()['feature.checks']->samples;
    $byResult = collect($samples)->keyBy(fn ($s) => $s->labels['result']);

    expect($byResult['active']->labels['feature'])->toBe('new-dashboard')
        ->and($byResult['active']->value)->toBe(1.0)
        ->and($byResult['inactive']->value)->toBe(1.0);
});

it('passes through scalar variant values as the result label', function () {
    app('events')->dispatch(new FeatureRetrieved('checkout-experiment', 1, 'treatment'));

    $samples = pennantFamilies()['feature.checks']->samples;

    expect($samples[0]->labels['result'])->toBe('treatment');
});

it('counts checks against an undefined feature', function () {
    app('events')->dispatch(new UnknownFeatureResolved('typo-feature', 1));

    $samples = pennantFamilies()['feature.unknown']->samples;

    expect($samples[0]->labels['feature'])->toBe('typo-feature')
        ->and($samples[0]->value)->toBe(1.0);
});

it('never leaks the scope into a label', function () {
    $scope = (object) ['id' => 42, 'email' => 'user@example.com'];

    app('events')->dispatch(new FeatureRetrieved('new-dashboard', $scope, true));

    $labels = pennantFamilies()['feature.checks']->samples[0]->labels;

    expect($labels)->toHaveKeys(['feature', 'result'])
        ->and($labels)->not->toHaveKey('scope');
});

it('records a check against an undefined feature as an event, once per trace', function () {
    $fake = Telemetry::fake();

    $first = Telemetry::tracer()->startSpan('GET /dashboard');
    app('events')->dispatch(new UnknownFeatureResolved('new-dashbaord', 1));
    app('events')->dispatch(new UnknownFeatureResolved('new-dashbaord', 2));
    app('events')->dispatch(new UnknownFeatureResolved('legacy-export', 1));
    $first->end();

    // The next request: a trace of its own.
    Telemetry::resetContext();
    $second = Telemetry::tracer()->startSpan('GET /dashboard');
    app('events')->dispatch(new UnknownFeatureResolved('new-dashbaord', 1));
    $second->end();

    $keys = array_map(fn ($event) => $event->attributes['feature_flag.key'], $fake->recordedEvents('feature.unknown'));

    expect($keys)->toBe(['new-dashbaord', 'legacy-export', 'new-dashbaord']);
});
