<?php

declare(strict_types=1);

/**
 * Runs in a SUBPROCESS so PHP's real shutdown sequence happens.
 *
 * The package registers flushOnShutdown() at boot. An application that awaits
 * its own outstanding HTTP work registers ITS callback later, so that one runs
 * later — and whatever completed there had nothing left to flush it. The fix
 * is a second flush registered from inside the first, which PHP appends to the
 * queue and therefore runs after the application's.
 *
 * Prints one line per exported span. The test asserts on that.
 */
require $argv[1].'/vendor/autoload.php';

use Cbox\Telemetry\Contracts\Exporter;
use Cbox\Telemetry\Support\ExportResult;
use Cbox\Telemetry\Support\SignalSet;
use Cbox\Telemetry\Support\TelemetryBatch;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\TelemetryServiceProvider;
use Cbox\Telemetry\Tracing\SpanKind;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;

$app = new Application($argv[1]);
$app->singleton('config', fn () => new Repository(['telemetry' => ['enabled' => true, 'store' => 'array']]));
Container::setInstance($app);

$app->register(TelemetryServiceProvider::class);

$telemetry = $app->make(TelemetryManager::class);

// Writes as it receives, so nothing depends on where a reporting callback
// would sit in the shutdown queue — which is the very thing under test.
$telemetry->addExporter(new class($argv[2]) implements Exporter
{
    public function __construct(private readonly string $path) {}

    public function name(): string
    {
        return 'file';
    }

    public function supports(): SignalSet
    {
        return SignalSet::all();
    }

    public function export(TelemetryBatch $batch): ExportResult
    {
        foreach ($batch->spans as $span) {
            file_put_contents($this->path, 'EXPORTED '.$span->name."\n", FILE_APPEND);
        }

        return ExportResult::ok();
    }
});

// The package's own handler, as register_shutdown_function would run it.
register_shutdown_function([$app->make(TelemetryServiceProvider::class, ['app' => $app]), 'flushOnShutdown']);

// The application's: awaits outstanding work and completes a call. Registered
// after the package's, so it runs after it.
$pending = $telemetry->tracer()->startDetachedSpan('GET awaited.example', SpanKind::Client);

register_shutdown_function(static function () use ($pending): void {
    $pending->end();
});

echo "SCRIPT DONE\n";
