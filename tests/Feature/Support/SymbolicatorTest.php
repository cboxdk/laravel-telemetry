<?php

declare(strict_types=1);

use Cbox\Telemetry\Support\Symbolicator;
use Illuminate\Support\Facades\Storage;

function sym(): Symbolicator
{
    return new Symbolicator(Storage::fake('local'), 'telemetry/sourcemaps');
}

// A hand-verified v3 map:
//   line 1: seg "AAAA"=[0,0,0,0] at genCol 0, seg "CAAC"=[1,0,0,1] at genCol 1
//   line 2: seg "AACA"=[0,0,1,0] -> src line accumulates to 2
//   plus a 5-field "AAAAA" name mapping.
$map = [
    'version' => 3,
    'sources' => ['src.ts'],
    'names' => ['foo'],
    'mappings' => 'AAAA,CAAC;AACA',
];

it('resolves generated positions to the original source (VLQ decode)', function () use ($map) {
    $s = sym();

    expect($s->resolve($map, 1, 0))->toMatchArray(['source' => 'src.ts', 'line' => 1, 'column' => 0])
        ->and($s->resolve($map, 1, 1))->toMatchArray(['source' => 'src.ts', 'line' => 1, 'column' => 1])
        // line 2 accumulates source line AND column across the whole file
        // (the 'CAAC' segment on line 1 carried the source column to 1)
        ->and($s->resolve($map, 2, 0))->toMatchArray(['source' => 'src.ts', 'line' => 2, 'column' => 1]);
});

it('picks the segment with the largest generated column <= target', function () use ($map) {
    // col 5 on line 1 -> the last qualifying segment (genCol 1)
    expect(sym()->resolve($map, 1, 5))->toMatchArray(['column' => 1]);
});

it('parses Chrome and Firefox stack frames', function () {
    $chrome = "Error: boom\n    at doThing (https://app.test/build/app-abc.js:10:20)\n    at https://app.test/build/app-abc.js:5:1";
    $ff = 'doThing@https://app.test/build/app-abc.js:10:20';

    $c = Symbolicator::parseStack($chrome);
    expect($c)->toHaveCount(2)
        ->and($c[0])->toMatchArray(['function' => 'doThing', 'file' => 'https://app.test/build/app-abc.js', 'line' => 10, 'column' => 20])
        ->and($c[1]['function'])->toBeNull();

    expect(Symbolicator::parseStack($ff)[0])->toMatchArray(['function' => 'doThing', 'line' => 10, 'column' => 20]);
});

it('symbolicates a stack against an uploaded map', function () use ($map) {
    $disk = Storage::fake('local');
    $disk->put('telemetry/sourcemaps/v9/app-abc.js.map', json_encode($map));
    $s = new Symbolicator($disk, 'telemetry/sourcemaps');

    // browser column is 1-based -> resolver uses 0-based internally
    $frames = $s->symbolicateStack('v9', 'at foo (https://app.test/build/app-abc.js:1:1)');

    expect($frames[0]['original'])->toBeTrue()
        ->and($frames[0]['file'])->toBe('src.ts')
        ->and($frames[0]['line'])->toBe(1);
});

it('leaves frames unresolved when no map exists', function () {
    $frames = sym()->symbolicateStack('unknown', 'at x (https://app.test/build/none.js:1:1)');
    expect($frames[0]['original'])->toBeFalse();
});
