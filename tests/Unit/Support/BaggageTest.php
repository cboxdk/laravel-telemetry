<?php

declare(strict_types=1);

use Cbox\Telemetry\Support\Baggage;

it('encodes attributes as a comma-separated, percent-encoded key=value list', function () {
    expect(Baggage::encode(['team.id' => 42, 'plan' => 'pro plan']))
        ->toBe('team.id=42,plan=pro%20plan');
});

it('round-trips through parse', function () {
    $header = Baggage::encode(['team.id' => 42, 'note' => 'a, b; c']);

    expect(Baggage::parse($header))->toBe(['team.id' => '42', 'note' => 'a, b; c']);
});

it('drops null attributes on encode', function () {
    expect(Baggage::encode(['a' => 'x', 'b' => null]))->toBe('a=x');
});

it('returns null for nothing to encode', function () {
    expect(Baggage::encode([]))->toBeNull()
        ->and(Baggage::encode(['a' => null]))->toBeNull();
});

it('discards W3C baggage properties on parse — only key=value round-trips', function () {
    expect(Baggage::parse('team.id=42;prop1=value1;prop2'))->toBe(['team.id' => '42']);
});

it('parses multiple members separated by commas with optional whitespace', function () {
    expect(Baggage::parse('a=1, b=2 , c=3'))->toBe(['a' => '1', 'b' => '2', 'c' => '3']);
});

it('ignores malformed members without an equals sign', function () {
    expect(Baggage::parse('a=1,garbage,b=2'))->toBe(['a' => '1', 'b' => '2']);
});

it('returns an empty map for null or empty headers', function () {
    expect(Baggage::parse(null))->toBe([])
        ->and(Baggage::parse(''))->toBe([]);
});

it('caps the encoded header at the W3C 8192-byte budget', function () {
    $attributes = [];

    foreach (range(1, 500) as $i) {
        $attributes["key{$i}"] = str_repeat('x', 50);
    }

    $header = Baggage::encode($attributes);

    expect(strlen((string) $header))->toBeLessThanOrEqual(8192);
});

it('caps the number of members at 180', function () {
    $attributes = [];

    foreach (range(1, 300) as $i) {
        $attributes["key{$i}"] = 'v';
    }

    expect(count(Baggage::parse(Baggage::encode($attributes))))->toBeLessThanOrEqual(180);
});
