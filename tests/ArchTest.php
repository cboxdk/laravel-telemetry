<?php

declare(strict_types=1);

arch('no debug calls left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'echo'])
    ->not->toBeUsed();

arch('strict types everywhere')
    ->expect('Cbox\Telemetry')
    ->toUseStrictTypes();

arch('contracts are interfaces')
    ->expect('Cbox\Telemetry\Contracts')
    ->toBeInterfaces();

arch('exceptions extend the package base exception')
    ->expect('Cbox\Telemetry\Exceptions')
    ->classes()
    ->toExtend('Cbox\Telemetry\Exceptions\TelemetryException')
    ->ignoring('Cbox\Telemetry\Exceptions\TelemetryException');

arch('the core never depends on Laravel facades')
    ->expect(['Cbox\Telemetry\Metrics', 'Cbox\Telemetry\Tracing', 'Cbox\Telemetry\Support'])
    ->not->toUse('Illuminate\Support\Facades');

arch('final by default')
    ->expect('Cbox\Telemetry')
    ->classes()
    ->toBeFinal()
    ->ignoring([
        // Extended by Testing\TelemetryFake.
        'Cbox\Telemetry\TelemetryManager',
        // Host packages may extend the provider (the Statamic overlay does).
        'Cbox\Telemetry\TelemetryServiceProvider',
        // Extended by test doubles that fake the wire.
        'Cbox\Telemetry\Exporters\Otlp\OtlpTransport',
        // Deliberately open so Storage::shouldReceive()/partialMock() work.
        'Cbox\Telemetry\Instrumentation\InstrumentedFilesystemManager',
        // Abstract base — subclassed by every package exception.
        'Cbox\Telemetry\Exceptions\TelemetryException',
    ]);

arch('no blocking sleeps outside the known daemon loops and spin locks')
    ->expect(['sleep', 'usleep'])
    ->not->toBeUsed()
    ->ignoring([
        'Cbox\Telemetry\Console\FlushCommand',           // --interval daemon loop
        'Cbox\Telemetry\Console\MonitorCommand',         // daemon loop
        'Cbox\Telemetry\Metrics\Stores\ApcuMetricStore', // index spin lock
    ]);

arch('no env() reads (config-cache safety) and no hard process exits')
    ->expect(['env', 'exit', 'die'])
    ->not->toBeUsed();

// Invariant #2: never KEYS/SCAN a Redis keyspace, never iterate the full
// APCu keyspace. Pest's arch layer can't see method calls, so this pins
// the promise at the source level.
test('no KEYS/SCAN or APCu keyspace iteration anywhere in src', function () {
    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__).'/src', FilesystemIterator::SKIP_DOTS),
    );

    /** @var SplFileInfo $file */
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $stripped = preg_replace('~//[^\n]*|/\*.*?\*/~s', '', (string) file_get_contents($file->getPathname())) ?? '';

        if (preg_match('/->\s*(keys|scan|hscan|sscan|zscan)\s*\(|new\s+\\\\?APCuIterator|apcu_(?:cache_info|key_info)\s*\(/i', $stripped)) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([]);
});
