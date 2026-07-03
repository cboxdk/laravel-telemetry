<?php

declare(strict_types=1);

use Cbox\Telemetry\Contracts\ManagesRequestState;
use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Instrumentation\CacheInstrumentation;
use Cbox\Telemetry\Instrumentation\CommandInstrumentation;
use Cbox\Telemetry\Instrumentation\HttpClientInstrumentation;
use Cbox\Telemetry\Instrumentation\MailInstrumentation;
use Cbox\Telemetry\Instrumentation\NotificationInstrumentation;
use Cbox\Telemetry\Instrumentation\QueueInstrumentation;
use Cbox\Telemetry\Instrumentation\TransactionInstrumentation;
use Cbox\Telemetry\Testing\CollectingExporter;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;

it('every stateful instrumentation implements the reset contract', function (string $class) {
    expect(new $class(app()))->toBeInstanceOf(ManagesRequestState::class);
})->with([
    CacheInstrumentation::class,
    HttpClientInstrumentation::class,
    MailInstrumentation::class,
    NotificationInstrumentation::class,
    TransactionInstrumentation::class,
    CommandInstrumentation::class,
    QueueInstrumentation::class,
]);

it('drops half-open state so a leaked entry never crosses Octane requests', function () {
    $cache = new CacheInstrumentation(app());
    $cache->register(app('events'), counters: false, spans: true);

    // Simulate a request that began a cache read but died before the hit.
    (function () {
        $this->begin('array', 'orphan-key');
    })->call($cache);

    $pending = (fn () => $this->pending)->call($cache);
    expect($pending)->toHaveKey('array:orphan-key');

    // Octane RequestReceived → flushRequestState.
    $cache->flushRequestState();

    expect((fn () => $this->pending)->call($cache))->toBe([]);
});

it('resets an open transaction stack between requests', function () {
    $tx = new TransactionInstrumentation(app());

    (function () {
        $this->stacks['mysql'][] = null;
    })->call($tx);

    $tx->flushRequestState();

    expect((fn () => $this->stacks)->call($tx))->toBe([]);
});

it('re-arms the gate hook after Octane flushes the Gate singleton', function () {
    $collector = new CollectingExporter;
    Telemetry::addExporter($collector);

    Gate::define('do-thing', fn () => true);

    // Request 1.
    Gate::forUser(new GenericUser(['id' => 1]))->allows('do-thing');

    // Octane flushes the Gate between requests (it is in octane.flush).
    app()->forgetInstance(Illuminate\Contracts\Auth\Access\Gate::class);
    Gate::clearResolvedInstance('gate');

    // Request 2 — a fresh Gate. Without afterResolving re-arming, this
    // check would record nothing.
    Gate::define('do-thing', fn () => true);
    Gate::forUser(new GenericUser(['id' => 2]))->allows('do-thing');

    $checks = collect(Telemetry::collect())
        ->keyBy(fn ($f) => $f->name())['authorization.checks']->samples;

    // Both requests counted — not just the first. And exactly 2, so the
    // WeakMap guard prevented double-arming from double-counting.
    expect(collect($checks)->sum('value'))->toBe(2.0);
});
