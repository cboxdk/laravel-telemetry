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

it('names the Blade template behind a compiled view, without its compiled line', function () {
    $dir = sys_get_temp_dir().'/telemetry-'.uniqid().'/framework/views';
    mkdir($dir, 0777, true);
    $compiled = $dir.'/'.sha1('x').'.php';

    file_put_contents($compiled, "<?php return \\Cbox\\Telemetry\\Support\\SourceLocator::locate('/app'); ?>\n"
        .'<?php /**PATH /app/resources/views/orders/index.blade.php ENDPATH**/ ?>');

    $location = require $compiled;

    expect($location)->toBe(['code.file.path' => 'resources/views/orders/index.blade.php']);

    unlink($compiled);
});
