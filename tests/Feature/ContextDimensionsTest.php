<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Testing\CollectingExporter;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);
});

function collected(CollectingExporter $collector): array
{
    Telemetry::flush();

    $spans = [];
    $events = [];

    foreach ($collector->batches() as $batch) {
        array_push($spans, ...$batch->spans);
        array_push($events, ...$batch->events);
    }

    return [$spans, $events];
}

it('merges context dimensions into every span, with span-specific winning', function () {
    Telemetry::context(['team.name' => 'platform', 'plan' => 'pro']);

    Telemetry::span('work.outer', function () {
        Telemetry::span('work.inner', fn ($span) => $span->setAttribute('plan', 'overridden'));
    });

    [$spans] = collected($this->collector);

    $outer = collect($spans)->firstWhere('name', 'work.outer');
    $inner = collect($spans)->firstWhere('name', 'work.inner');

    expect($outer->attributes()['team.name'])->toBe('platform')
        ->and($outer->attributes()['plan'])->toBe('pro')
        ->and($inner->attributes()['team.name'])->toBe('platform')
        ->and($inner->attributes()['plan'])->toBe('overridden');
});

it('applies context to events and telemetry-channel logs', function () {
    config()->set('logging.channels.telemetry', ['driver' => 'telemetry', 'level' => 'debug']);

    Telemetry::context(['team.name' => 'billing']);

    Telemetry::event('invoice.sent', ['invoice.id' => 7]);
    Log::channel('telemetry')->info('Invoice mailed');

    [, $events] = collected($this->collector);

    $event = collect($events)->firstWhere('name', 'invoice.sent');
    $log = collect($events)->firstWhere('name', 'Invoice mailed');

    expect($event->attributes['team.name'])->toBe('billing')
        ->and($event->attributes['invoice.id'])->toBe(7)
        ->and($log->attributes['team.name'])->toBe('billing');
});

it('adds bounded request labels to the duration histogram via labelRequestsUsing', function () {
    Route::get('/plans', fn () => 'ok');

    Telemetry::labelRequestsUsing(fn ($request) => ['plan' => 'enterprise', 'team' => 'core']);

    $this->get('/plans');

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());
    $sample = $families['http.server.request.duration']->samples[0];

    expect($sample->labels['plan'])->toBe('enterprise')
        ->and($sample->labels['team'])->toBe('core')
        ->and($sample->labels['http.route'])->toBe('/plans');
});

it('never lets resolver labels overwrite the core labels', function () {
    Route::get('/sneaky', fn () => 'ok');

    Telemetry::labelRequestsUsing(fn () => ['http.route' => 'evil', 'plan' => 'ok']);

    $this->get('/sneaky');

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());
    $sample = $families['http.server.request.duration']->samples[0];

    expect($sample->labels['http.route'])->toBe('/sneaky')
        ->and($sample->labels['plan'])->toBe('ok');
});

it('a failing label resolver never breaks the request', function () {
    Route::get('/fine', fn () => 'ok');

    Telemetry::labelRequestsUsing(fn () => throw new RuntimeException('resolver down'));

    $this->get('/fine')->assertOk();

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());

    expect($families['http.server.request.duration']->samples[0]->labels['http.route'])->toBe('/fine');
});

it('restores dispatcher context and origin on the worker side', function () {
    app('queue'); // register listeners

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\Jobs\SendInvoice');
    $job->shouldReceive('getQueue')->andReturn('billing');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('payload')->andReturn([
        'telemetry' => [
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
            'context' => ['team.name' => 'checkout', 'plan' => 'pro'],
            'origin' => 'POST /orders',
        ],
    ]);

    $events = app('events');
    $events->dispatch(new JobProcessing('redis', $job));
    $events->dispatch(new JobProcessed('redis', $job));

    [$spans, $jobEvents] = collected($this->collector);

    $consumer = collect($spans)->firstWhere(fn ($span) => str_contains($span->name, 'SendInvoice'));

    expect($consumer->traceId)->toBe('0af7651916cd43dd8448eb211c80319c')
        ->and($consumer->parentSpanId)->toBe('b7ad6b7169203331')
        ->and($consumer->attributes()['messaging.origin.name'])->toBe('POST /orders')
        // Inherited dimensions:
        ->and($consumer->attributes()['team.name'])->toBe('checkout')
        ->and($consumer->attributes()['plan'])->toBe('pro');
});

it('clears context between jobs', function () {
    Telemetry::context(['team.name' => 'stale']);

    Telemetry::resetContext();

    expect(Telemetry::contextAttributes())->toBe([]);
});
