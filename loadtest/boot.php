<?php

declare(strict_types=1);
/**
 * What the package costs at BOOT, which under PHP-FPM is paid on every
 * single request — inproc.php boots once and hides it.
 *
 *   docker compose -f loadtest/docker-compose.yml exec app \
 *     php loadtest/boot.php with 50
 *   docker compose -f loadtest/docker-compose.yml exec app \
 *     php loadtest/boot.php without 50
 *
 * The difference is the service provider: registration, the config
 * merge, and wiring every instrumentation's listeners.
 */
require '/app/vendor/autoload.php';
putenv('APP_KEY=base64:'.base64_encode(str_repeat('a', 32)));

use Cbox\Telemetry\TelemetryServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Orchestra\Testbench\Foundation\Application as Testbench;

$withProvider = ($argv[1] ?? 'with') === 'with';
$n = (int) ($argv[2] ?? 30);
$samples = [];

for ($i = 0; $i < $n; $i++) {
    $t = hrtime(true);
    $app = Testbench::create(
        basePath: '/app/loadtest/workbench',
        options: ['extra' => ['providers' => $withProvider ? [TelemetryServiceProvider::class] : []]],
    );
    $app->make(Kernel::class)->bootstrap();
    $samples[] = (hrtime(true) - $t) / 1e6;
    unset($app);
}

sort($samples);
printf("%-5s provider  n=%d  p50=%7.3fms  mean=%7.3fms\n", $withProvider ? 'with' : 'without', $n, $samples[(int) ($n * 0.5)], array_sum($samples) / $n);
