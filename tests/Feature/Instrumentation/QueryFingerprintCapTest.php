<?php

declare(strict_types=1);

use Cbox\Telemetry\Instrumentation\QueryInstrumentation;
use Cbox\Telemetry\TelemetryManager;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;

/**
 * One trace can run an unbounded number of DISTINCT queries — a bulk
 * import, a migration, a long job — and the N+1 detector is keyed by
 * statement fingerprint. Without a cap the map grows for as long as the
 * trace does, which in a queue worker is as long as the job.
 */
it('does not grow its fingerprint map without bound inside one trace', function () {
    $instrumentation = new QueryInstrumentation(app());

    $counts = new ReflectionProperty(QueryInstrumentation::class, 'queryCounts');
    $cap = (new ReflectionClassConstant(QueryInstrumentation::class, 'MAX_FINGERPRINTS'))->getValue();

    $counts->setValue($instrumentation, array_fill_keys(
        array_map(static fn (int $i): string => 'fingerprint-'.$i, range(1, $cap)),
        1,
    ));

    expect($counts->getValue($instrumentation))->toHaveCount($cap);

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getName')->andReturn('mysql');
    $connection->shouldReceive('getDriverName')->andReturn('mysql');

    (new ReflectionMethod(QueryInstrumentation::class, 'detectDuplicate'))->invoke(
        $instrumentation,
        app(TelemetryManager::class),
        new QueryExecuted('select 1', [], 0.1, $connection),
    );

    // Cleared rather than frozen at the cap: N+1 detection restarts from
    // zero, which loses nothing that matters, and the memory stays flat
    // instead of holding ten thousand stale counts for the rest of the
    // job.
    expect($counts->getValue($instrumentation))->toHaveCount(1);
});
