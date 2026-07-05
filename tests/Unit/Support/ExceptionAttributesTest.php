<?php

declare(strict_types=1);

use Cbox\Telemetry\Support\ExceptionAttributes;

it('captures the OTel exception attributes plus a fingerprint', function () {
    $e = new RuntimeException('boom');

    $attrs = ExceptionAttributes::from($e);

    expect($attrs['exception.type'])->toBe(RuntimeException::class)
        ->and($attrs['exception.message'])->toBe('boom')
        ->and($attrs['exception.file'])->toContain('ExceptionAttributesTest.php')
        ->and($attrs['exception.line'])->toBeInt()
        ->and($attrs['exception.stacktrace'])->toBeString()
        ->and($attrs['exception.group'])->toMatch('/^[0-9a-f]{12}$/');
});

it('fingerprints by type + site: same site groups, different type/site differ', function () {
    $make = fn () => new RuntimeException('varying message '.random_int(1, 9));

    // Same throw site (this closure), different messages -> same group.
    $a = ExceptionAttributes::fingerprint($make());
    $b = ExceptionAttributes::fingerprint($make());

    // Different type at the same-ish site -> different group.
    $c = ExceptionAttributes::fingerprint(new LogicException('x'));

    expect($a)->toBe($b)
        ->and($a)->not->toBe($c);
});

it('makes paths project-relative when a base path is given', function () {
    $e = new RuntimeException('x');

    $attrs = ExceptionAttributes::from($e, dirname(__DIR__, 3));

    expect($attrs['exception.file'])->toStartWith('tests/')
        ->and($attrs['exception.file'])->not->toContain('/Users/');
});

it('attaches source context only when opted in', function () {
    $e = new RuntimeException('x');

    expect(ExceptionAttributes::from($e))->not->toHaveKey('exception.source')
        ->and(ExceptionAttributes::from($e, withSource: true)['exception.source'])->toContain('> ');
});
