<?php

declare(strict_types=1);

use Cbox\Telemetry\Support\Cast;

it('narrows strings, accepting scalars and falling back otherwise', function () {
    expect(Cast::string('hello'))->toBe('hello')
        ->and(Cast::string(42))->toBe('42')
        ->and(Cast::string(1.5))->toBe('1.5')
        ->and(Cast::string(['nope'], 'default'))->toBe('default')
        ->and(Cast::string(null, 'default'))->toBe('default');
});

it('narrows ints, accepting numeric strings and floats', function () {
    expect(Cast::int(5))->toBe(5)
        ->and(Cast::int(5.9))->toBe(5)
        ->and(Cast::int('42'))->toBe(42)
        ->and(Cast::int('abc', 7))->toBe(7)
        ->and(Cast::int(null, 7))->toBe(7);
});

it('narrows floats, accepting numeric strings and ints', function () {
    expect(Cast::float(1.5))->toBe(1.5)
        ->and(Cast::float(3))->toBe(3.0)
        ->and(Cast::float('2.5'))->toBe(2.5)
        ->and(Cast::float('abc', 9.0))->toBe(9.0);
});

it('narrows bools strictly, never coercing truthy/falsy values', function () {
    expect(Cast::bool(true))->toBeTrue()
        ->and(Cast::bool(false))->toBeFalse()
        ->and(Cast::bool('true', true))->toBeTrue()
        ->and(Cast::bool(1, true))->toBeTrue()
        ->and(Cast::bool(null, true))->toBeTrue();
});

it('narrows arrays, defaulting to empty for non-arrays', function () {
    expect(Cast::array(['a' => 1]))->toBe(['a' => 1])
        ->and(Cast::array('nope'))->toBe([]);
});

it('narrows string-keyed arrays, dropping non-string keys', function () {
    expect(Cast::stringKeyedArray(['a' => 1, 0 => 'skip', 'b' => 2]))->toBe(['a' => 1, 'b' => 2])
        ->and(Cast::stringKeyedArray('nope'))->toBe([]);
});

it('narrows string lists, dropping non-string items and reindexing', function () {
    expect(Cast::stringList(['a', 1, 'b', null, 'c']))->toBe(['a', 'b', 'c'])
        ->and(Cast::stringList('nope'))->toBe([]);
});

it('narrows string maps, keeping only string => string pairs', function () {
    expect(Cast::stringMap(['a' => 'x', 'b' => 1, 0 => 'y']))->toBe(['a' => 'x'])
        ->and(Cast::stringMap('nope'))->toBe([]);
});

it('narrows scalar maps, keeping string keys with scalar or null values', function () {
    expect(Cast::scalarMap(['a' => 'x', 'b' => 1, 'c' => null, 'd' => ['nested'], 0 => 'skip']))
        ->toBe(['a' => 'x', 'b' => 1, 'c' => null])
        ->and(Cast::scalarMap('nope'))->toBe([]);
});
