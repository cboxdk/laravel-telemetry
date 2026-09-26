<?php

declare(strict_types=1);

use Cbox\Telemetry\Support\PersonalData;
use Cbox\Telemetry\Support\Redactor;

/**
 * The redaction patterns run on strings an attacker can influence — a
 * URL query, a validation message echoing what someone typed, an
 * exception carrying a payload. A pattern that degrades badly on one of
 * those is a denial of service reachable from a form field, and it
 * fires at flush, in the worker, after the response.
 */
function adversarial(string $value, array $overrides = []): float
{
    $redactor = Redactor::fromConfig(
        $overrides + ['pii' => ['detectors' => PersonalData::availableDetectors()]] + config('telemetry.redaction'),
    );

    $start = hrtime(true);
    $redactor->value('log.context.message', $value);

    return (hrtime(true) - $start) / 1_000_000;
}

it('stays fast on the input that used to take a second', function () {
    // `a.a.a…@b.b.b…` — dots in both the character class and the
    // separator, which is the classic shape. 128KB of it took 374ms
    // before the value cap and 1.9s before the pattern was rewritten.
    //
    // Sized to fit UNDER the scan ceiling (max_value_length x 16), so
    // the email pattern actually runs on it. At 128KB the value is
    // replaced outright without being scanned, and the test measured
    // the ceiling rather than the pattern — which is the same as not
    // testing the pattern.
    $value = str_repeat('a.', 8_000).'@'.str_repeat('b.', 8_000);

    expect(strlen($value))->toBeLessThan(4096 * 16)
        ->and(adversarial($value))->toBeLessThan(10.0);
});

it('bounds every pathological shape, not just that one', function (string $value) {
    expect(adversarial($value))->toBeLessThan(10.0);
})->with([
    'digits' => [str_repeat('1', 200_000)],
    'digits and separators' => [str_repeat('1-', 100_000)],
    'phone-ish' => ['+45'.str_repeat('(1)', 60_000)],
    'iban-ish' => ['DK00'.str_repeat('A', 200_000)],
    'query explosion' => ['?'.str_repeat('a=1&', 50_000)],
    'token soup' => [str_repeat('Aa1', 60_000)],
    'nested urls' => [str_repeat('url=https://x/?token=abc&', 10_000)],
]);

it('marks the cut rather than truncating silently', function () {
    $capped = Redactor::fromConfig(['max_value_length' => 64] + config('telemetry.redaction'))
        ->value('log.context.message', str_repeat('x', 500));

    // Somebody will eventually debug against a value that ends in the
    // middle of the answer. Say that it does.
    expect($capped)->toEndWith('… (truncated)')
        ->and(strlen($capped))->toBeLessThan(120);
});

it('leaves an ordinary value untouched', function () {
    $message = 'SQLSTATE[23000]: Duplicate entry for key users_email_unique';

    expect(Redactor::fromConfig(config('telemetry.redaction'))->value('log.context.message', $message))
        ->toBe($message);
});

it('can have the cap turned off by an operator who means it', function () {
    $long = str_repeat('x', 50_000);

    expect(Redactor::fromConfig(['max_value_length' => 0] + config('telemetry.redaction'))
        ->value('log.context.message', $long))->toBe($long);
});

it('does not let the truncation boundary split a secret', function () {
    // Codex found this in the commit that introduced the cap. Pad so
    // `https://alice:hunter2` ends exactly at the boundary and the `@`
    // that identifies it as userinfo falls on the far side: cutting
    // first removed the evidence, and the password came out in full
    // with a `(truncated)` marker after it.
    //
    // The secret being SHORT is the whole problem. My comment claimed
    // the opposite — that a secret longer than the cap is unrealistic —
    // which is true and beside the point.
    $cap = 4096;
    $credential = 'https://alice:hunter2';
    $padding = str_repeat('x', $cap - strlen($credential));

    $out = Redactor::fromConfig(config('telemetry.redaction'))
        ->value('log.context.message', $padding.$credential.'@api.test/charges');

    expect($out)->not->toContain('hunter2');
});

it('replaces a value too long to scan rather than scanning half of it', function () {
    // Past the ceiling the value is not truncated, it is replaced. A
    // half-scanned secret is a leak and an unscanned one is worse, and
    // nobody was going to read a 64KB attribute anyway.
    $out = Redactor::fromConfig(config('telemetry.redaction'))
        ->value('log.context.message', str_repeat('x', 4096 * 16 + 1).'password=hunter2');

    expect($out)->toBe('[REDACTED]')
        ->not->toContain('hunter2');
});

it('stays fast on the shapes that only bite once the cap is off', function (string $value) {
    // With max_value_length at 0 there is no scan ceiling either, so
    // the patterns see the whole value. Every quantifier in them is
    // bounded for this reason: the userinfo pattern's unbounded scheme
    // made 62KiB of `a+.-` cost 198ms of CPU, inside the request whose
    // telemetry it was scrubbing.
    expect(adversarial($value, ['max_value_length' => 0]))->toBeLessThan(25.0);
})->with([
    'scheme-shaped run' => [str_repeat('a+.-', 16_000).'@'],
    'many schemes, no match' => ['@'.str_repeat('a://', 8_000).str_repeat('b', 30_000)],
    'jwt prefix, no dots' => ['eyJ'.str_repeat('a', 60_000)],
    'bearer with a long tail' => ['Bearer '.str_repeat('a', 60_000)],
]);

it('redacts the whole credential, however long it is', function (string $value, string $secret) {
    // The bounds these patterns briefly carried did not stop a single
    // adversarial input measurably, and each one ended the match early:
    // a token one character past the cap kept its tail, and a password
    // past it stopped matching at all. What a pattern no longer covers
    // is exported verbatim, so a length limit here is a disclosure with
    // a performance story attached.
    $out = Redactor::fromConfig(['max_value_length' => 0] + config('telemetry.redaction'))
        ->value('log.context.message', $value);

    expect($out)->not->toContain($secret);
})->with([
    'bearer with a long token' => ['Bearer '.str_repeat('a', 4_096).'TAILSECRET', 'TAILSECRET'],
    'userinfo with a long password' => ['https://alice:hunter2'.str_repeat('a', 4_096).'@example.test', 'hunter2'],
    'jwt with a long payload' => [
        'eyJhbGciOiJIUzI1NiJ9.'.str_repeat('p', 5_000).'SECRETCLAIM.c2lnbmF0dXJl',
        'SECRETCLAIM',
    ],
]);
