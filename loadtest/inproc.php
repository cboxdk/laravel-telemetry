<?php

declare(strict_types=1);
/**
 * Per-request cost, in-process: boot once, then handle N requests through
 * the real HTTP kernel.
 *
 * No nginx, no FPM, no network and no queueing, which is the point. The
 * HTTP rig measures those too, and on a laptop they dominate: p50 went
 * 19ms at one concurrent connection to 56ms at sixteen with nothing else
 * changed, and the package's own share is under a millisecond. This
 * harness resolves ±0.06ms.
 *
 *   docker compose -f loadtest/docker-compose.yml exec app \
 *     php loadtest/inproc.php flat 600
 *
 * Run it for BOTH states and pair the results — TELEMETRY_ENABLED=0 is
 * the baseline, and the difference is the package:
 *
 *   for s in 0 1; do ... -e TELEMETRY_ENABLED=$s app php loadtest/inproc.php work 600; done
 *
 * What it does NOT include is the boot, which under PHP-FPM is paid on
 * every request. See boot.php.
 */
require '/app/vendor/autoload.php';

putenv('APP_KEY=base64:'.base64_encode(str_repeat('a', 32)));

use Cbox\Telemetry\TelemetryServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Foundation\Application as Testbench;

$app = Testbench::create(
    basePath: '/app/loadtest/workbench',
    options: ['extra' => ['providers' => [TelemetryServiceProvider::class]]],
);
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

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

$route = $argv[1] ?? 'work';
$n = (int) ($argv[2] ?? 300);

// Warm every code path before timing.
for ($i = 0; $i < 50; $i++) {
    $request = Request::create("/{$route}", 'GET');
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
}

$samples = [];

for ($i = 0; $i < $n; $i++) {
    $request = Request::create("/{$route}", 'GET');
    $t = hrtime(true);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    $samples[] = (hrtime(true) - $t) / 1e6;
}

sort($samples);
printf(
    "%-6s n=%d  p50=%7.3fms  p90=%7.3fms  mean=%7.3fms  rss=%.1fMB\n",
    $route,
    $n,
    $samples[(int) ($n * 0.5)],
    $samples[(int) ($n * 0.9)],
    array_sum($samples) / $n,
    memory_get_usage(true) / 1048576,
);
