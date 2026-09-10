<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Metrics\Registry;
use Cbox\Telemetry\Metrics\Stores\ArrayMetricStore;
use Cbox\Telemetry\Metrics\Stores\BufferedMetricStore;

/**
 * Requests flush in TraceRequest, jobs in QueueInstrumentation, scheduled tasks
 * in ScheduleInstrumentation. A plain artisan command had no flush point unless
 * instrument.commands was on, and it defaults to off — so with buffer_writes
 * (also the default) everything such a command measured sat in the in-memory
 * buffer and died with the process.
 *
 * The metric that made this visible: queue.jobs.dispatched is counted in the
 * DISPATCHING process, so a command queueing 10 000 jobs reported none of them
 * while the worker reported all 10 000 processed.
 */
it('drains a buffered metric store when the app terminates', function () {
    $backing = new ArrayMetricStore;
    app()->instance(Registry::class, new Registry(new BufferedMetricStore($backing), []));

    Telemetry::counter('probe.dispatched')->inc(7);

    expect($backing->collect())->toBeEmpty('the buffer should not have reached the store yet');

    app()->terminate();

    $families = collect($backing->collect())->keyBy(fn ($family) => $family->name());

    expect($families)->toHaveKey('probe.dispatched')
        ->and($families['probe.dispatched']->samples[0]->value)->toBe(7.0);
});
