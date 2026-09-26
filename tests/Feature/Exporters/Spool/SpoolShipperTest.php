<?php

declare(strict_types=1);

use Cbox\Telemetry\Exporters\Spool\ArraySpool;
use Cbox\Telemetry\Exporters\Spool\SpoolShipper;
use Cbox\Telemetry\Support\ExportResult;

/**
 * The drain is the daemon's whole job, and it is also the thing that
 * can stop the daemon being one.
 */
it('stops on its budget instead of draining a backlog in one call', function () {
    // An outage leaves a backlog, and draining it until empty is one
    // call that runs for as long as the backlog takes. For all of it
    // the daemon flushes no metrics and never looks at its stop flag,
    // so SIGTERM is answered by the supervisor's kill timeout.
    $spool = new ArraySpool;

    for ($i = 0; $i < 5_000; $i++) {
        $spool->push(['signal' => 'traces', 'payload' => ['resourceSpans' => [['i' => $i]]]]);
    }

    $shipper = new SpoolShipper($spool, fn (): ExportResult => ExportResult::ok());

    $result = $shipper->ship(maxBatch: 100, maxEntries: 1_000);

    expect($result->shipped)->toBe(1_000)
        ->and($result->drained)->toBeFalse()
        ->and($spool->size())->toBe(4_000);
});

it('comes back to a stop flag between batches', function () {
    $spool = new ArraySpool;

    for ($i = 0; $i < 1_000; $i++) {
        $spool->push(['signal' => 'traces', 'payload' => ['resourceSpans' => [['i' => $i]]]]);
    }

    $shipper = new SpoolShipper($spool, fn (): ExportResult => ExportResult::ok());

    $result = $shipper->ship(maxBatch: 100, shouldStop: fn (): bool => true);

    expect($result->shipped)->toBe(100)
        ->and($result->drained)->toBeFalse();
});

it('reports a spool it actually emptied as drained', function () {
    $spool = new ArraySpool;
    $spool->push(['signal' => 'traces', 'payload' => ['resourceSpans' => []]]);

    $result = (new SpoolShipper($spool, fn (): ExportResult => ExportResult::ok()))->ship();

    expect($result->drained)->toBeTrue()
        ->and($spool->size())->toBe(0);
});

it('stops on a wall-clock budget, not only an entry count', function () {
    // An entry budget bounds the work and not the time it takes. Ten
    // thousand entries at a slow POST each is minutes in which the
    // daemon flushes no metrics — and after SIGTERM, minutes past the
    // supervisor's grace period.
    $spool = new ArraySpool;

    for ($i = 0; $i < 5_000; $i++) {
        $spool->push(['signal' => 'traces', 'payload' => ['resourceSpans' => [['i' => $i]]]]);
    }

    $shipper = new SpoolShipper($spool, function (): ExportResult {
        usleep(20_000);

        return ExportResult::ok();
    });

    $started = microtime(true);
    $result = $shipper->ship(maxBatch: 10, maxEntries: 5_000, maxSeconds: 0.2);

    expect(microtime(true) - $started)->toBeLessThan(1.0)
        ->and($result->drained)->toBeFalse()
        ->and($spool->size())->toBeGreaterThan(0);
});
