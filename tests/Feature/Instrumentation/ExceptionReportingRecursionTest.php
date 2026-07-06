<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Testing\CollectingExporter;

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);
});

it('does not recurse when auth is unbootable during report()', function () {
    // A persistently failing auth resolution — the exact mid-failure state
    // the enduser.id lookup runs into when e.g. the database is down. The
    // factory throws a bounded number of times so a regression surfaces as
    // a call-count explosion instead of memory exhaustion.
    $calls = 0;

    app()->bind('auth', function () use (&$calls) {
        $calls++;

        if ($calls < 25) {
            throw new LogicException('auth container unbootable #'.$calls);
        }

        return new class
        {
            public function user(): null
            {
                return null;
            }
        };
    });

    report(new RuntimeException('original failure'));
    Telemetry::flush();

    // Without re-entrancy protection FailSafe::handle() feeds the auth
    // failure back into report(), which re-enters this very subscriber:
    // every cycle costs one auth resolution, so the counter races to the
    // factory's bound (26 before the latch). With the latch the package
    // attempts auth at most twice (original + one nested report); Laravel's
    // own Handler::context() adds one self-guarded lookup per report.
    expect($calls)->toBeLessThanOrEqual(6);

    // The original exception still made it out as a structured record.
    $recorded = collect($this->collector->batches())
        ->flatMap(fn ($b) => $b->events)
        ->filter(fn ($e) => $e->name === 'exception')
        ->filter(fn ($e) => ($e->attributes['exception.message'] ?? null) === 'original failure');

    expect($recorded)->not->toBeEmpty();
});
