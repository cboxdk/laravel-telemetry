<?php

declare(strict_types=1);

use Cbox\Telemetry\Events\TelemetryEvent;
use Cbox\Telemetry\Support\Redactor;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\SpanLink;

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

it('adds custom keys and patterns to the package lists rather than replacing them', function () {
    // A published config that copied the lists cannot receive an entry added
    // later, and the entries added later are the ones that catch newly
    // understood credential spellings. So an app's lists are UNIONED in, and
    // `user.password` stays redacted whatever else the app added.
    $redactor = redactor([
        'keys' => ['cpr'],
        'patterns' => ['/\d{6}-\d{4}/' => '[CPR]'],
        'replacement' => '(gone)',
    ]);

    expect($redactor->value('customer.cpr', '010203-1234'))->toBe('(gone)')
        ->and($redactor->value('note.body', 'cpr is 010203-1234'))->toBe('cpr is [CPR]')
        ->and($redactor->value('user.password', 'still a package key'))->toBe('(gone)');
});

it('hands the lists over entirely when an app asks to replace them', function () {
    // The escape hatch for an app that means it: with replace_defaults on,
    // what is configured is the whole of it.
    $redactor = redactor([
        'keys' => ['cpr'],
        'patterns' => ['/\d{6}-\d{4}/' => '[CPR]'],
        'replacement' => '(gone)',
        'replace_defaults' => true,
    ]);

    expect($redactor->value('customer.cpr', '010203-1234'))->toBe('(gone)')
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

it('redacts a short credential scheme value without eating the words around it', function () {
    // `dXNlcjpwYXNz` is base64 for user:pass and only twelve characters, so a
    // flat sixteen-character threshold published it. The discriminator is that
    // a credential carries something other than lowercase letters, which the
    // sentence "Basic authentication is required" does not.
    $redactor = Redactor::fromConfig(['enabled' => true]);

    expect($redactor->value('exception.message', 'rejected: Basic dXNlcjpwYXNz'))
        ->toBe('rejected: Basic [REDACTED]')
        ->and($redactor->value('log.line', 'Bearer abc12345 expired'))
        ->toBe('Bearer [REDACTED] expired')
        ->and($redactor->value('log.line', 'Basic authentication is required'))
        ->toBe('Basic authentication is required')
        ->and($redactor->value('log.line', 'Basic authorization header missing'))
        ->toBe('Basic authorization header missing');
});

it('does not let a quote inside a credential end the redaction', function () {
    $redactor = Redactor::fromConfig(['enabled' => true]);

    expect($redactor->value('log.line', "Invalid password=abc'SECRET supplied"))
        ->toBe('Invalid password=[REDACTED] supplied')
        ->and($redactor->value('log.line', 'token=ab"cd more'))
        ->toBe('token=[REDACTED] more');
});

it('leaves capitalised authentication prose alone', function () {
    // An English word capitalises only its first letter; a base64 payload does
    // not. Requiring the non-lowercase character somewhere after the first is
    // what keeps this sentence readable while `dXNlcjpwYXNz` still goes.
    $redactor = Redactor::fromConfig(['enabled' => true]);

    expect($redactor->value('log.line', 'Basic Authentication is required'))
        ->toBe('Basic Authentication is required')
        ->and($redactor->value('log.line', 'Bearer Scheme expected'))
        ->toBe('Bearer Scheme expected')
        ->and($redactor->value('log.line', 'rejected: Basic dXNlcjpwYXNz'))
        ->toBe('rejected: Basic [REDACTED]');
});

it('matches a credential by its decoded name anywhere it turns up', function () {
    // A pattern matches literal text, so `%74oken=` walks past one written for
    // `token=`. The NAME is decoded instead of the value — decoding the value
    // would publish something the caller never sent.
    $redactor = Redactor::fromConfig(['enabled' => true]);

    expect($redactor->value('exception.message', 'GET https://x.test/?%74oken=SECRET failed'))
        ->toBe('GET https://x.test/?%74oken=[REDACTED] failed')
        ->and($redactor->value('log.line', 'url with token[name]=SECRET'))
        ->toBe('url with token[name]=[REDACTED]')
        ->and($redactor->value('log.line', 'token%5Ba%5D%5Bb%5D=SECRET'))
        ->toBe('token%5Ba%5D%5Bb%5D=[REDACTED]')
        ->and($redactor->value('http.request.header.referer', 'https://x/cb?%63ode=SECRET'))
        ->toBe('https://x/cb?%63ode=[REDACTED]');
});

it('keeps the ambiguous names out of loose prose', function () {
    // `code`, `key`, `state` and `auth` only mean a credential inside a real
    // query. At the start of a value they are a cache key and a status.
    $redactor = Redactor::fromConfig(['enabled' => true]);

    foreach (['key=abc', 'cache.key=user:42', 'code=200', 'state=open'] as $value) {
        expect($redactor->value('cache.key', $value))->toBe($value);
    }

    // `pwd` is ambiguous for the same reason: `pwd=/srv/app` is a working
    // directory in any shell-flavoured log. Inside a query it is a password.
    expect($redactor->value('log.line', 'pwd=/srv/app is the cwd'))
        ->toBe('pwd=/srv/app is the cwd')
        ->and($redactor->value('url.query', 'page=1&pwd=hunter2'))
        ->toBe('page=1&pwd=[REDACTED]');

    // `sig`, `jwt` and `otp` are never ordinary, so they need no query.
    expect($redactor->value('log.line', 'otp=998877 sent'))
        ->toBe('otp=[REDACTED] sent');
});

it('leaves a WWW-Authenticate challenge readable', function () {
    // `realm=api` and `error=invalid_token` are diagnostics, not credentials.
    // The old sixteen-character rule ate them because `=` and `_` counted
    // towards the length.
    $redactor = Redactor::fromConfig(['enabled' => true]);

    expect($redactor->value('log.line', 'Basic realm=api'))->toBe('Basic realm=api')
        ->and($redactor->value('log.line', 'Bearer error=insufficient_scope'))->toBe('Bearer error=insufficient_scope')
        ->and($redactor->value('log.line', 'Basic YTpi'))->toBe('Basic [REDACTED]')
        ->and($redactor->value('log.line', 'Bearer abc123'))->toBe('Bearer [REDACTED]');
});

it('cannot be stalled by a parameter name full of open brackets', function () {
    // `(?:\[[^\]]*\])+$` is quadratic on a name that opens brackets and never
    // closes them — and it raised no PCRE error, so the fail-closed guard
    // never saw it. It was reachable from a query string.
    $name = 'token'.str_repeat('[', 200_000).']tail';

    $started = hrtime(true);
    $result = Redactor::parameterIsCredential($name);

    expect($result)->toBeTrue()
        ->and((hrtime(true) - $started) / 1e6)->toBeLessThan(50.0);
});

it('leaves a value already carrying the replacement alone', function () {
    // A replacement containing a space used to be re-matched as far as the
    // space and replaced again, appending its own tail on every pass.
    $redactor = Redactor::fromConfig(['enabled' => true, 'replacement' => '[HIDDEN VALUE]']);

    $once = $redactor->value('url.query', 'token=SECRET&x=1');

    expect($once)->toBe('token=[HIDDEN VALUE]&x=1')
        ->and($redactor->value('url.query', $once))->toBe($once);
});

it('keeps an exemption list the app narrowed', function () {
    // `keys` and `patterns` are rules, so unioning the package's in can only
    // redact more. `safe_keys` are exemptions, and adding them back would
    // re-expose what an app deliberately stopped exempting.
    expect(Redactor::fromConfig(['enabled' => true, 'safe_keys' => []])->value('session.id', 'SESSION_VALUE'))
        ->toBe('[REDACTED]');
});

it('lets an app that takes the lists over turn off the name heuristics too', function () {
    $redactor = Redactor::fromConfig([
        'enabled' => true,
        'replace_defaults' => true,
        'keys' => [],
        'patterns' => [],
        'safe_keys' => [],
    ]);

    expect($redactor->value('log.message', 'token=SECRET'))->toBe('token=SECRET');
});

it('redacts a credential by key in a structure, before anything encodes it', function () {
    // The log channel json_encodes a non-scalar context value, and once it is
    // a string the key is gone. Done on the array instead — decoding the JSON
    // again at export cannot tell an empty object from an empty array and
    // rewrites big integers, corrupting structures with nothing to hide.
    $redactor = Redactor::fromConfig(['enabled' => true]);

    expect($redactor->redactStructure(['password' => 'hunter2', 'api_key' => 'sk_live', 'user' => 'bob']))
        ->toBe(['password' => '[REDACTED]', 'api_key' => '[REDACTED]', 'user' => 'bob'])
        ->and($redactor->redactStructure(['a' => ['b' => ['token' => 'SECRET', 'page' => 2]]]))
        ->toBe(['a' => ['b' => ['token' => '[REDACTED]', 'page' => 2]]])
        ->and($redactor->redactStructure(['postal_code' => '2100', 'sort_key' => 'price']))
        ->toBe(['postal_code' => '2100', 'sort_key' => 'price']);
});

it('never rewrites a JSON attribute value it has no business changing', function () {
    // An earlier attempt decoded and re-encoded any JSON attribute. It turned
    // `{}` into `[]`, `{"0":"a"}` into a list, and dropped the precision of
    // every big integer and float literal — on values with nothing in them to
    // redact.
    $redactor = Redactor::fromConfig(['enabled' => true]);

    foreach ([
        '{"meta":{}}',
        '{"0":"zero","1":"one"}',
        '{"n":1.0}',
        '{"id":9223372036854775809}',
        '{"amount":1e400}',
    ] as $value) {
        expect($redactor->value('log.context.payload', $value))->toBe($value);
    }
});

it('scrubs a span name and a span event name, not only the attributes beside them', function () {
    // `Telemetry::span()` and nameRequestSpansUsing() take whatever the app
    // hands them, and a name built from a URL carries its query along. The
    // same credential used to go out scrubbed in the attributes and verbatim
    // in the name next to them.
    $redactor = Redactor::fromConfig(['enabled' => true]);

    $span = new Span(
        traceId: str_repeat('a', 32),
        spanId: str_repeat('b', 16),
        parentSpanId: null,
        name: 'GET https://x/?token=SECRET',
        kind: SpanKind::Server,
        sampled: true,
        attributes: [],
        onEnd: static function (): void {},
    );
    $span->addEvent('token=EVENT_SECRET');

    $redactor->spans([$span]);

    expect($span->name)->toBe('GET https://x/?token=[REDACTED]')
        ->and($span->events()[0]->name)->toBe('token=[REDACTED]');
});

it('does not mistake a value that merely starts with the replacement', function () {
    // `token=[REDACTED]SECRET` starts with the replacement and is emphatically
    // not redacted. A prefix says nothing without a boundary after it, and an
    // empty replacement matches everywhere — which silently turned the whole
    // pass off.
    $redactor = Redactor::fromConfig(['enabled' => true]);

    expect($redactor->value('log.line', 'token=[REDACTED]SECRET'))->toBe('token=[REDACTED]')
        ->and(Redactor::fromConfig(['enabled' => true, 'replacement' => ''])->value('log.line', 'token=SECRET'))
        ->toBe('token=');
});

it('looks inside a URL that an ordinary assignment carries', function () {
    // logfmt: the outer `url=` is not a credential, but the match consumed its
    // value and the query inside it along with it.
    $redactor = Redactor::fromConfig(['enabled' => true]);

    expect($redactor->value('log.line', 'url=https://x.test/?token=SECRET'))
        ->toBe('url=https://x.test/?token=[REDACTED]');
});

it('stays linear on a value that is almost all credentials', function () {
    // Comparing the replacement with substr()+str_starts_with copied the rest
    // of the input per match: 640KB of `token=x&` took 349ms.
    //
    // Asserted as a SHAPE rather than a stopwatch. A wall-clock budget has to
    // hold on a loaded CI box running twelve suites at once, so it has to be
    // loose enough to pass the very quadratic behaviour it is guarding. Four
    // times the input costs about four times as much when linear and sixteen
    // when not, and both measurements are slowed equally by whatever else the
    // machine is doing.
    $redactor = Redactor::fromConfig(['enabled' => true]);

    $time = function (int $pairs) use ($redactor): float {
        $value = str_repeat('token=x&', $pairs);

        $started = hrtime(true);
        $redactor->value('log.line', $value);

        return (hrtime(true) - $started) / 1e6;
    };

    $time(5_000); // warm up: first call pays for pattern compilation

    $small = $time(20_000);
    $large = $time(80_000);

    expect($large / max($small, 0.1))->toBeLessThan(8.0);
});

it('walks a span link\'s attributes too', function () {
    // Links reach the exporter like any other attributes and were the one set
    // redaction never touched — a retried job's link to its previous attempt
    // carries whatever the app put on it.
    $redactor = Redactor::fromConfig(['enabled' => true]);

    $link = new SpanLink(str_repeat('c', 32), str_repeat('d', 16), ['password' => 'LINK_SECRET', 'attempt' => 2]);

    $span = new Span(
        traceId: str_repeat('a', 32),
        spanId: str_repeat('b', 16),
        parentSpanId: null,
        name: 'retry',
        kind: SpanKind::Internal,
        sampled: true,
        attributes: [],
        onEnd: static function (): void {},
        startUnixNano: null,
        links: [$link],
    );

    $redactor->spans([$span]);

    expect($span->links()[0]->attributes)->toBe(['password' => '[REDACTED]', 'attempt' => 2]);
});
