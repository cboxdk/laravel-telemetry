<?php

declare(strict_types=1);

use Cbox\Telemetry\Events\TelemetryEvent;
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

it('scrubs log messages, not just attributes', function () {
    $redactor = redactor();

    $events = $redactor->events([new TelemetryEvent(
        name: 'refresh failed for Bearer abcdef1234567890abcdef',
        timeUnixNano: 1,
        severityNumber: 17,
        severityText: 'ERROR',
    )]);

    expect($events[0]->name)->toBe('refresh failed for Bearer [REDACTED]');
});

it('applies the custom hook to log messages', function () {
    $redactor = redactor();
    $redactor->redactUsing(fn (string $key, string $value) => $key === 'log.message' ? strtoupper($value) : null);

    $log = $redactor->events([new TelemetryEvent('sensitive line', 1, severityNumber: 9, severityText: 'INFO')]);
    $event = $redactor->events([new TelemetryEvent('order.placed', 1)]);

    expect($log[0]->name)->toBe('SENSITIVE LINE')
        ->and($event[0]->name)->toBe('order.placed');
});

it('redacts sensitive keys regardless of value type', function () {
    $redactor = redactor();

    $out = $redactor->attributes([
        'user.password' => 12345678,          // int
        'auth' => true,                        // bool
        'card_number' => 4111111111111111,     // int
        'cvv' => 123,                          // int
        'order.total' => 999,                  // safe int, kept
        'http.status' => 200,                  // kept
    ]);

    expect($out['user.password'])->toBe('[REDACTED]')
        ->and($out['auth'])->toBe('[REDACTED]')
        ->and($out['card_number'])->toBe('[REDACTED]')
        ->and($out['cvv'])->toBe('[REDACTED]')
        ->and($out['order.total'])->toBe(999)
        ->and($out['http.status'])->toBe(200);
});

it('keeps tracking the package lists when an app appends its own', function () {
    // Spreading rather than replacing is what the config comment tells users
    // to do; this pins that it works, so an app with a custom pattern still
    // receives ours.
    $redactor = Redactor::fromConfig([
        'enabled' => true,
        'patterns' => [...Redactor::defaultPatterns(), '/\bCPR-\d+/' => '[REDACTED:cpr]'],
    ]);

    expect($redactor->value('note', 'CPR-123456 and ?token=SECRET'))
        ->toBe('[REDACTED:cpr] and ?token=[REDACTED]');
});

it('catches a credential quoted in prose, not only one in a query string', function () {
    // The pattern's reach is the point: the same secret shows up in url.query,
    // in a referer, and in the exception message that quotes the failing call.
    // Anchoring on `?`/`&`/`;` alone covered the first two and left the third
    // — the one an error report is most likely to carry — in the clear.
    $redactor = Redactor::fromConfig(['enabled' => true]);

    expect($redactor->value('exception.message', 'Invalid api_token=sk_live_9 supplied'))
        ->toBe('Invalid api_token=[REDACTED] supplied')
        ->and($redactor->value('log.line', 'err: token=abc123 was rejected'))
        ->toBe('err: token=[REDACTED] was rejected')
        ->and($redactor->value('log.line', 'Guzzle error with password=hunter2'))
        ->toBe('Guzzle error with password=[REDACTED]');
});

it('still leaves names that merely end in a credential word alone', function () {
    // The guard is that the credential word has to sit immediately before the
    // `=`. Widening the separator must not start eating ordinary telemetry:
    // these are real attribute values from this package and its consumers.
    $redactor = Redactor::fromConfig(['enabled' => true]);

    $untouched = [
        'tokens_in=880 tokens_out=120 cost=0.004',
        'signature_required=true',
        'db.statement: SELECT id, token_count FROM ai_usage WHERE id=?',
        'secret_count=3',
        'api_key_name=prod',
        'sort_key=price',
        'postal_code=2100',
    ];

    foreach ($untouched as $value) {
        expect($redactor->value('attr', $value))->toBe($value);
    }
});

it('takes the whole credential when its value carries a delimiter or quotes', function () {
    // Two ways a value used to escape: a literal `;` ended the match and
    // published everything after it, and a doubled quote defeated the single
    // optional one so nothing matched at all. Over-redacting a `;`-separated
    // query is the right way to be wrong about a credential.
    $redactor = Redactor::fromConfig(['enabled' => true]);

    expect($redactor->value('url.query', 'access_token=abc;secret-suffix'))
        ->toBe('access_token=[REDACTED]')
        ->and($redactor->value('log.line', 'access_token=""SECRET'))
        ->toBe('access_token=[REDACTED]')
        ->and($redactor->value('url.query', '?api_token=sk_live_1&page=2'))
        ->toBe('?api_token=[REDACTED]&page=2');
});
