<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\TelemetryServiceProvider;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\SpanStatus;

/**
 * A fatal error — max_execution_time, an allocation over memory_limit, an
 * uncaught Error — never reaches Kernel::terminate(), so neither the
 * terminating callback nor the request middleware's flush runs.
 *
 * Laravel's own shutdown handler DOES convert the fatal into a FatalError and
 * push it through report(), which this package turns into an exception record
 * — and that record then sat in the event buffer and died with the process. So
 * the one class of failure you most want an error tracker for produced nothing
 * at all: no error, no trace, and an open request span never exported.
 */
it('delivers the error and the open span when the process dies without terminating', function () {
    $collector = new CollectingExporter;
    Telemetry::addExporter($collector);

    // Mid-request: a span is open and Laravel has just reported the fatal.
    $span = Telemetry::span('GET /slow');
    report(new RuntimeException('Maximum execution time exceeded'));

    expect($collector->batches())->toBeEmpty('nothing should have shipped yet');

    // The shutdown handler's body, as register_shutdown_function would run it.
    app(TelemetryServiceProvider::class, ['app' => app()])->flushOnShutdown();

    $spans = collect($collector->batches())->flatMap(fn ($batch) => $batch->spans);
    $events = collect($collector->batches())->flatMap(fn ($batch) => $batch->events);

    expect($spans->pluck('name'))->toContain('GET /slow')
        ->and($spans->firstWhere('name', 'GET /slow')->status())->toBe(SpanStatus::Error)
        ->and($events->pluck('name'))->toContain('exception');

    expect($span->hasEnded())->toBeTrue();
});

it('flushes a call the application finished in its own shutdown callback', function () {
    // Run in a SUBPROCESS, because this is about PHP's real shutdown ordering
    // and nothing else can exercise it: calling flushOnShutdown() directly —
    // as the test above does — never runs the callback it registers, which is
    // why the fix went out with no test that could fail without it.
    //
    // An application that awaits its own outstanding HTTP work registers that
    // callback after the package's, so it runs later, and what completed there
    // had nothing left to flush it.
    $exported = tempnam(sys_get_temp_dir(), 'telemetry-shutdown-');

    $script = __DIR__.'/../Support/late-shutdown-flush.php';

    exec(sprintf('%s %s %s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($script), escapeshellarg(dirname(__DIR__, 2)), escapeshellarg($exported)), $output, $status);

    expect($status)->toBe(0, 'subprocess failed: '.implode("\n", $output))
        ->and($output)->toContain('SCRIPT DONE')
        ->and(trim((string) file_get_contents($exported)))->toBe('EXPORTED GET awaited.example');

    @unlink($exported);
});
