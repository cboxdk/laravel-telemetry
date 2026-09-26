<?php

declare(strict_types=1);

use Cbox\Telemetry\Support\FailSafe;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Exceptions;

/**
 * The guards sit on paths that run per query, per cache operation, per
 * Redis command. A backend that is down does not fail once — it fails
 * tens of thousands of times a minute, and reporting each one turns a
 * degraded dashboard into a log-volume incident on a pipeline usually
 * shared with the application this class exists to protect.
 */
beforeEach(function () {
    $this->reported = [];

    FailSafe::handleExceptionsUsing(function (Throwable $e): void {
        $this->reported[] = $e->getMessage();
    });
});

afterEach(function () {
    FailSafe::handleExceptionsUsing(null);
});

it('reports a repeating failure once, not once per occurrence', function () {
    $fail = static fn () => throw new RuntimeException('the metric store is refusing writes');

    for ($i = 0; $i < 10_000; $i++) {
        FailSafe::guard($fail);
    }

    expect($this->reported)->toHaveCount(1);
});

it('still reports a different failure', function () {
    // Throttling per failure, not globally: a second, unrelated problem
    // appearing during an outage is exactly what you need to hear.
    FailSafe::guard(static fn () => throw new RuntimeException('store refusing writes'));
    FailSafe::guard(static fn () => throw new LogicException('something else entirely'));

    expect($this->reported)->toHaveCount(2);
});

it('keys on where it was thrown, not on the message', function () {
    // A message usually carries the key, the host or the id that varied,
    // so keying on it would defeat the throttle exactly when the failure
    // is high-volume.
    $fail = static fn (int $i) => throw new RuntimeException("cannot write key user:{$i}");

    for ($i = 0; $i < 500; $i++) {
        FailSafe::guard(static fn () => $fail($i));
    }

    expect($this->reported)->toHaveCount(1);
});

it('does not grow its map without bound', function () {
    $tracked = new ReflectionProperty(FailSafe::class, 'reported');
    $cap = (new ReflectionClassConstant(FailSafe::class, 'MAX_TRACKED'))->getValue();

    // Distinct throw sites are bounded by the code, but a pathological
    // one could iterate; the map resets rather than growing in a worker
    // that lives for days.
    for ($i = 0; $i < $cap + 10; $i++) {
        $tracked->setValue(null, $tracked->getValue() + ['synthetic-'.$i => true]);
    }

    FailSafe::guard(static fn () => throw new RuntimeException('one more'));

    expect(count($tracked->getValue()))->toBeLessThanOrEqual($cap);
});

it('still swallows what it declines to report', function () {
    // The throttle must never turn into a rethrow.
    for ($i = 0; $i < 100; $i++) {
        $result = FailSafe::guard(static fn () => throw new RuntimeException('same again'));

        expect($result)->toBeNull();
    }

    expect($this->reported)->toHaveCount(1);
});

it('routes a failure to the application own error handler by default', function () {
    // The point of the default: Sentry, Bugsnag and the log channels
    // all hook `report()`, which is the same function `rescue()` calls.
    // Nothing is hidden — it goes exactly where the application already
    // sends its errors.
    FailSafe::handleExceptionsUsing(null);

    $reported = null;
    app()->make(ExceptionHandler::class);

    Exceptions::fake();

    FailSafe::guard(static fn () => throw new RuntimeException('the store is refusing writes'));

    Exceptions::assertReported(
        static fn (RuntimeException $e): bool => $e->getMessage() === 'the store is refusing writes',
    );

    expect($reported)->toBeNull();
});

it('separates two different problems that share a throw site', function () {
    // Two container bindings failing from the same line of the same
    // vendor file, through the same guard. Keyed on class and location
    // alone they are one failure, and the second is silenced for a
    // minute — which is exactly when you need to hear about it.
    FailSafe::guard(static fn () => throw new RuntimeException('Target class [BillingClient] does not exist'));
    FailSafe::guard(static fn () => throw new RuntimeException('Target class [ShippingClient] does not exist'));

    expect($this->reported)->toHaveCount(2);
});

it('still collapses the same problem with a different id in it', function () {
    // And the reason the message is not simply part of the key: a
    // message carrying the row, the host or the key that varied is the
    // high-volume case the throttle exists for.
    for ($i = 0; $i < 500; $i++) {
        FailSafe::guard(static fn () => throw new RuntimeException("cannot write key user:{$i} on shard 7"));
    }

    expect($this->reported)->toHaveCount(1);
});
