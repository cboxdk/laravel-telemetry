<?php

declare(strict_types=1);

use Cbox\Telemetry\Support\SourceLocator;

it('points at the first application frame, relative to the base path', function () {
    $line = __LINE__ + 1;
    $location = SourceLocator::locate(dirname(__DIR__, 3));

    expect($location)->not->toBeNull()
        ->and($location['code.file.path'])->toBe('tests/Unit/Support/SourceLocatorTest.php')
        ->and($location['code.line.number'])->toBe($line);
});

it('keeps the absolute path when the file is outside the base path', function () {
    $location = SourceLocator::locate('/somewhere/else');

    expect($location['code.file.path'])->toBe(__FILE__);
});
