<?php

declare(strict_types=1);

/**
 * The daemon side of the rig: `telemetry:flush`, against the same app
 * the load goes through.
 *
 * Without this the spool could be filled and never drained, so half the
 * architecture — the half that exists precisely so a slow collector
 * cannot touch the request path — had no way to be exercised here at
 * all. Arguments pass straight through:
 *
 *   php loadtest/flush.php                    # one drain, then exit
 *   php loadtest/flush.php --daemon --interval=1
 */

use Cbox\Telemetry\TelemetryServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Orchestra\Testbench\Foundation\Application as Testbench;
use Symfony\Component\Console\Output\ConsoleOutput;

require __DIR__.'/../vendor/autoload.php';

putenv('APP_KEY=base64:'.base64_encode(str_repeat('a', 32)));

$app = Testbench::create(
    basePath: __DIR__.'/workbench',
    options: ['extra' => ['providers' => [TelemetryServiceProvider::class]]],
);

$kernel = $app->make(Kernel::class);

// `call()` takes an associative array, not a raw argv slice: passing
// the slice makes Symfony read the positional index as an argument name
// and answer "The "1" argument does not exist."
$arguments = [];

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--')) {
        [$name, $value] = array_pad(explode('=', $argument, 2), 2, true);
        $arguments[$name] = $value;
    }
}

exit($kernel->call('telemetry:flush', $arguments, new ConsoleOutput) === 0
    ? 0
    : 1);
