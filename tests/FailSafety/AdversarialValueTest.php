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
    $value = str_repeat('a.', 32_000).'@'.str_repeat('b.', 32_000);

    expect(adversarial($value))->toBeLessThan(10.0);
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
