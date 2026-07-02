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
