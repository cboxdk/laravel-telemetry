<?php

declare(strict_types=1);

use Cbox\Telemetry\Support\Redactor;

function redactor(array $config = []): Redactor
{
    return Redactor::fromConfig($config);
}

it('replaces values whose key segments match a sensitive word', function (string $key) {
    expect(redactor()->value($key, 'hunter2'))->toBe('[REDACTED]');
})->with([
    'user.password',
    'stripe.api_key',
    'http.request.header.authorization',
    'card_number',
    'app.credentials.primary',
]);

it('matches whole key segments, not substrings', function () {
    $redactor = redactor();

    expect($redactor->value('cache.key', 'users:7'))->toBe('users:7')
        ->and($redactor->value('monkey.business', 'ok'))->toBe('ok')
        ->and($redactor->keyIsSensitive('tokenizer.name'))->toBeFalse();
});

it('scrubs secrets embedded in any string value', function () {
    $redactor = redactor();

    $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';

    expect($redactor->value('exception.message', "auth failed for {$jwt}"))->toBe('auth failed for [REDACTED:jwt]')
        ->and($redactor->value('exception.message', 'header was Bearer abcdef1234567890abcdef'))->toBe('header was Bearer [REDACTED]')
        ->and($redactor->value('db.query.text', 'connect to redis://admin:hunter2@cache.internal:6379'))->toBe('connect to redis://[REDACTED]@cache.internal:6379');
});

it('supports custom keys, patterns and replacement', function () {
    $redactor = redactor([
        'keys' => ['cpr'],
        'patterns' => ['/\d{6}-\d{4}/' => '[CPR]'],
        'replacement' => '(gone)',
    ]);

    expect($redactor->value('customer.cpr', '010203-1234'))->toBe('(gone)')
        ->and($redactor->value('note.body', 'cpr is 010203-1234'))->toBe('cpr is [CPR]')
        ->and($redactor->value('user.password', 'left alone — defaults were overridden'))->toBe('left alone — defaults were overridden');
});

it('runs the custom hook last and survives a broken one', function () {
    $redactor = redactor();
    $redactor->redactUsing(fn (string $key, string $value) => $key === 'weird.field' ? 'hooked' : null);

    expect($redactor->value('weird.field', 'anything'))->toBe('hooked')
        ->and($redactor->value('other.field', 'kept'))->toBe('kept');

    $redactor->redactUsing(function () {
        throw new RuntimeException('broken hook');
    });

    expect($redactor->value('other.field', 'still kept'))->toBe('still kept');
});

it('skips patterns that fail to compile', function () {
    $redactor = redactor(['patterns' => ['/[broken' => 'x']]);

    expect($redactor->value('some.field', 'value'))->toBe('value');
});

it('does nothing when disabled', function () {
    $redactor = redactor(['enabled' => false]);

    expect($redactor->value('user.password', 'hunter2'))->toBe('hunter2');
});
