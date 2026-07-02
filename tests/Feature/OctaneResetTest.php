<?php

declare(strict_types=1);

use Cbox\Telemetry\Contracts\ManagesRequestState;
use Cbox\Telemetry\Instrumentation\CacheInstrumentation;
use Cbox\Telemetry\Instrumentation\CommandInstrumentation;
use Cbox\Telemetry\Instrumentation\HttpClientInstrumentation;
use Cbox\Telemetry\Instrumentation\MailInstrumentation;
use Cbox\Telemetry\Instrumentation\NotificationInstrumentation;
use Cbox\Telemetry\Instrumentation\QueueInstrumentation;
use Cbox\Telemetry\Instrumentation\TransactionInstrumentation;

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
