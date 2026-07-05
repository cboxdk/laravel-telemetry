<?php

declare(strict_types=1);

use Cbox\Telemetry\Http\Controllers\SourcemapController;
use Cbox\Telemetry\TelemetryManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

function upload(array $body, array $sourcemaps, ?string $bearer = null): Response
{
    config()->set('telemetry.enabled', true);
    config()->set('telemetry.sourcemaps', $sourcemaps + [
        'prefix' => 'telemetry/sourcemaps',
        'disk' => 'local',
        'max_bytes' => 20 * 1024 * 1024,
    ]);

    $request = Request::create('/telemetry/sourcemaps', 'POST', $body);
    if ($bearer !== null) {
        $request->headers->set('Authorization', "Bearer {$bearer}");
    }

    return (new SourcemapController)($request, app(TelemetryManager::class));
}

$validMap = json_encode(['version' => 3, 'sources' => ['a.ts'], 'names' => [], 'mappings' => 'AAAA']);

it('404s when source maps are disabled', function () use ($validMap) {
    expect(fn () => upload(
        ['release' => 'v1', 'name' => 'app.js.map', 'map' => $validMap],
        ['enabled' => false, 'token' => 'secret'],
    ))->toThrow(HttpException::class);
});

it('403s without the bearer token (secure by default)', function () use ($validMap) {
    expect(fn () => upload(
        ['release' => 'v1', 'name' => 'app.js.map', 'map' => $validMap],
        ['enabled' => true, 'token' => 'secret'],
    ))->toThrow(HttpException::class);

    expect(fn () => upload(
        ['release' => 'v1', 'name' => 'app.js.map', 'map' => $validMap],
        ['enabled' => true, 'token' => 'secret'],
        'wrong',
    ))->toThrow(HttpException::class);
});

it('stores a valid v3 map with the right token', function () use ($validMap) {
    $disk = Storage::fake('local');

    $response = upload(
        ['release' => 'v1.2.3', 'name' => 'app-abc.js.map', 'map' => $validMap],
        ['enabled' => true, 'token' => 'secret'],
        'secret',
    );

    expect($response->getStatusCode())->toBe(204);
    $disk->assertExists('telemetry/sourcemaps/v1.2.3/app-abc.js.map');
});

it('rejects a non-v3 payload but still 204s (fail-safe)', function () {
    $disk = Storage::fake('local');

    $response = upload(
        ['release' => 'v1', 'name' => 'bad.js.map', 'map' => json_encode(['version' => 2, 'mappings' => ''])],
        ['enabled' => true, 'token' => 'secret'],
        'secret',
    );

    expect($response->getStatusCode())->toBe(204);
    $disk->assertMissing('telemetry/sourcemaps/v1/bad.js.map');
});

it('sanitizes traversal in release and name', function () use ($validMap) {
    $disk = Storage::fake('local');

    upload(
        ['release' => '../../etc', 'name' => '../../evil.map', 'map' => $validMap],
        ['enabled' => true, 'token' => 'secret'],
        'secret',
    );

    // Nothing escapes the prefix: slug() strips '/', name is basename'd, so
    // every stored path stays under telemetry/sourcemaps/.
    foreach ($disk->allFiles() as $path) {
        expect($path)->toStartWith('telemetry/sourcemaps/');
    }
    $disk->assertMissing('etc/evil.map');
});
