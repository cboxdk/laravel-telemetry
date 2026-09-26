<?php

declare(strict_types=1);

use Cbox\Telemetry\Contracts\RedactsTelemetry;
use Cbox\Telemetry\Support\Redactor;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanKind;

/**
 * A name list is enough for context you set yourself — you chose the
 * key. It is not enough for auto-instrumentation, where the URLs belong
 * to whatever third-party API the application calls and nobody has
 * enumerated `?t=`, `?sas=`, or whatever the next vendor names its
 * token.
 *
 * So the model has two halves, and this asserts both: what the name
 * says, and what the value looks like when the name says nothing.
 */
function redactUrl(string $query): string
{
    $redactor = Redactor::fromConfig(config('telemetry.redaction'));

    $span = new Span('t', 's', null, 'x', SpanKind::Client, true, [
        'url.full' => 'https://api.test/v1?'.$query,
    ], static fn () => null);

    return (string) $redactor->spans([$span])[0]->attributes()['url.full'];
}

it('redacts every spelling of a credential parameter', function (string $name) {
    $secret = 'SECRETVALUE123abcXYZ0987';

    expect(redactUrl("{$name}={$secret}"))->not->toContain($secret);
})->with([
    // Plain, and the separators a header-ish spelling uses.
    'api_key', 'apikey', 'api-key', 'x-api-key', 'token', 'secret', 'password',
    // camelCase, which is what a JavaScript-facing API uses — and which
    // the matcher could not see until it learnt to split on a capital.
    'apiKey', 'accessToken', 'clientSecret', 'refreshToken',
    // Ambiguous words behind a qualifier that settles them. `key`
    // alone could be a sort key; `private_key` could not.
    'private_key', 'api_key', 'client_secret', 'session_id', 'sessionid',
    'access_code', 'master_key', 'consumer_secret',
    // Run-of-capitals names, where splitting per letter would be worse
    // than not splitting at all.
    'AWSAccessKeyId', 'X-Goog-Signature',
]);

it('redacts a value that looks like a credential whatever it was called', function (string $name) {
    // The half a name list cannot do. These are real parameter names
    // from real APIs, and no list will ever have them all.
    expect(redactUrl("{$name}=STRIPE_EXAMPLE_KEY_REMOVED_FROM_HISTORY"))
        ->not->toContain('STRIPE_EXAMPLE_KEY_REMOVED_FROM_HISTORY');
})->with(['t', 'k', 'sas', 'se', 'x', 'v', 'cursor', 'opaque']);

it('recognises the issuers that stamp their keys', function (string $value) {
    expect(redactUrl("q={$value}"))->not->toContain($value);
})->with([
    'STRIPE_EXAMPLE_KEY_REMOVED_FROM_HISTORY',
    'ghp_16C7e42F292c6912E7710c838347Ae178B4a',
    'xoxb-1234-5678-abcdefghijklmnop',
    'AKIAIOSFODNN7EXAMPLE',
    'AIzaSyD-1234567890abcdefghijklmnopqrst',
    'glpat-ABCdef123456789012345',
]);

it('leaves the things an operator actually needs to read', function (string $pair) {
    // Redaction that eats real data is its own outage. An operator who
    // cannot read the URLs stops trusting the tool, and then nobody
    // reads any of it.
    expect(redactUrl($pair))->toContain($pair);
})->with([
    'limit=25',
    'page=3',
    'sort=created_at',
    'q=hello+world',
    'slug=my-great-article-about-things',
    'date=2026-09-26T12:00:00Z',
    'id=1234567890',
    // A UUID is an identifier, and the most common long value in a real
    // query string. Excluded by shape, deliberately.
    'order=8f14e45f-ceea-467a-9c7f-1b2c3d4e5f60',
    // The ambiguous words behind a qualifier that does NOT settle them.
    // A blanket suffix rule caught all of these, which is how the first
    // attempt at this broke a test written to protect them.
    'postal_code=2100',
    'sort_key=price',
    'status_code=404',
    'zip_code=90210',
]);

it('sees a secret through percent-encoding on both sides', function () {
    // `%74oken` is `token` to the application and to no regex that does
    // not decode; and the value is encoded just as often as the name.
    expect(redactUrl('%74oken=SECRETVALUE123abcXYZ0987'))->not->toContain('SECRETVALUE123abcXYZ0987')
        ->and(redactUrl('q=sk%5Flive%5F4eC39HqLyjWDarjtT1zdp7dc'))->not->toContain('4eC39HqLyjWDarjtT1zdp7dc');
});

it('is configurable without writing a class', function () {
    config()->set('telemetry.redaction.credential_prefixes', ['acme_tok_']);
    config()->set('telemetry.redaction.value_shape', false);

    // The app's own issuer prefix, unioned with the package's.
    expect(redactUrl('q=acme_tok_abcdef'))->not->toContain('acme_tok_abcdef')
        ->and(redactUrl('q=STRIPE_EXAMPLE_KEY_REMOVED_FROM_HISTORY'))->not->toContain('4eC39HqLyjWDarjtT1zdp7dc');

    // Shape detection off: an unrecognised long value is kept.
    expect(redactUrl('t=SECRETVALUE123abcXYZ0987'))->toContain('SECRETVALUE123abcXYZ0987');
});

it('can be replaced outright by binding the contract', function () {
    // The escape hatch for an organisation whose redaction policy is
    // its own: a compliance list, a shared internal package, a scanner
    // that already exists elsewhere in the estate.
    app()->bind(RedactsTelemetry::class, fn (): RedactsTelemetry => new class implements RedactsTelemetry
    {
        public function spans(array $spans): array
        {
            return [];
        }

        public function events(array $events): array
        {
            return [];
        }

        public function redactStructure(array $values, int $depth = 0): array
        {
            return [];
        }

        public function redactUsing(?Closure $custom): void {}

        public function keyIsSensitive(string $key): bool
        {
            return true;
        }

        public function valueLooksLikeCredential(string $value): bool
        {
            return true;
        }

        public function personalDataDetectors(): array
        {
            // An implementation with no such concept says so, and
            // telemetry:doctor reports "not scrubbed" rather than
            // guessing.
            return [];
        }
    });

    expect(app(RedactsTelemetry::class)->spans([]))->toBe([])
        ->and(app(RedactsTelemetry::class)->keyIsSensitive('anything'))->toBeTrue();
});
