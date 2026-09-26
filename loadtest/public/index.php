<?php

declare(strict_types=1);

/**
 * The load-test target: a real Laravel application, served over real
 * HTTP, with this package installed.
 *
 * Testbench rather than a skeleton app, because the point is to measure
 * THIS package and a skeleton is a hundred megabytes of other people's
 * decisions to keep in sync. The kernel, the middleware stack and the
 * terminate phase are the real ones, which is what matters: the
 * package's cost is mostly in terminate, and a harness that skipped it
 * would measure nothing.
 *
 * TELEMETRY=0 in the environment boots the same app with the package
 * disabled. That is the baseline, and the delta between the two runs is
 * the only number here worth reading — absolute throughput from PHP's
 * built-in server is a property of the server.
 */

use Cbox\Telemetry\TelemetryServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Foundation\Application as Testbench;

require __DIR__.'/../../vendor/autoload.php';

// Everything is configured by environment variable, exactly as a
// deployment configures it — TELEMETRY_ENABLED, TELEMETRY_STORE,
// TELEMETRY_EXPORTERS, TELEMETRY_OTLP_SPOOL, TELEMETRY_TRACES_SAMPLE_RATE.
// No callback rewrites config behind the package's back, so what is
// measured is the package reading its own config the way it always does.
putenv('APP_KEY=base64:'.base64_encode(str_repeat('a', 32)));

$app = Testbench::create(
    basePath: __DIR__.'/../workbench',
    options: ['extra' => ['providers' => [TelemetryServiceProvider::class]]],
);

$app->make(Kernel::class)->bootstrap();

/*
 * Three shapes, because the package's cost is not uniform.
 *
 *   /flat    the floor: middleware and terminate, nothing else.
 *   /work    a realistic request — queries, cache, an outgoing call.
 *   /heavy   an N+1, which is where per-query cost stops being small.
 */
Route::get('/flat', static fn (): array => ['ok' => true]);

Route::get('/work', static function (): array {
    $db = app('db')->connection();
    $db->select('select 1');
    $db->select('select 2');
    $db->select('select 3');

    cache()->get('warm');
    cache()->put('warm', 1, 60);

    return ['ok' => true];
});

Route::get('/heavy', static function (): array {
    $db = app('db')->connection();

    for ($i = 0; $i < 50; $i++) {
        $db->select('select ? as n', [$i]);
    }

    return ['ok' => true];
});

$request = Request::capture();
$kernel = $app->make(Kernel::class);
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
