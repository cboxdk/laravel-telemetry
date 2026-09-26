<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Support\Redactor;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\Span;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Support\Facades\Http;

/**
 * `url.full` is required by semconv and is the attribute that turns "we
 * called stripe.com 400 times" into "we called THIS". It is also the
 * attribute most likely to carry a secret, because half the world still
 * puts tokens in query strings and some of it still puts credentials in
 * the authority.
 *
 * Adding it was only safe because the redaction engine already covers
 * both. That is a claim worth a test rather than a comment.
 */
function outgoingSpan(string $url): Span
{
    // The EXPORTED span, not the recorded one. Redaction is the last
    // thing that happens before an exporter sees a batch, and
    // Telemetry::fake() does not run it — a redaction test written
    // against the fake passes while proving nothing, which is how the
    // first draft of this file did exactly that.
    $http = Http::setHandler(static fn () => Create::promiseFor(new PsrResponse(200, [], 'ok')));

    $root = Telemetry::tracer()->startSpan('checkout');
    $http->get($url);
    $root->end();

    Telemetry::flush();

    $spans = [];

    foreach (test()->collector->batches() as $batch) {
        foreach ($batch->spans as $span) {
            if (isset($span->attributes()['url.full'])) {
                $spans[] = $span;
            }
        }
    }

    expect($spans)->not->toBeEmpty('no exported client span carried url.full');

    return $spans[0];
}

beforeEach(function () {
    // The real engine and a real exporter, as an application gets them.
    config()->set('telemetry.redaction.enabled', true);
    config()->set('telemetry.redaction.keys', Redactor::defaultKeys());
    config()->set('telemetry.redaction.patterns', Redactor::defaultPatterns());

    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);
});

it('strips credentials out of the authority', function () {
    $span = outgoingSpan('https://alice:hunter2@api.test/charges');

    expect($span->attributes()['url.full'])
        ->not->toContain('hunter2')
        ->not->toContain('alice:');
});

it('redacts a token in the query string', function () {
    $span = outgoingSpan('https://api.test/charges?api_key=sk_live_51H8xQ2&limit=2');

    expect($span->attributes()['url.full'])
        ->not->toContain('sk_live_51H8xQ2')
        // The shape of the call survives; only the secret goes.
        ->toContain('limit=2');
});

it('sees through percent-encoding, which is how this is usually missed', function () {
    // `%74oken` decodes to `token`. Matching parameter names as literal
    // text could never see that, which is why the engine decodes first.
    $span = outgoingSpan('https://api.test/charges?%74oken=sk_live_deadbeef');

    expect($span->attributes()['url.full'])->not->toContain('sk_live_deadbeef');
});

it('leaves an ordinary URL entirely alone', function () {
    // Redaction that eats real data is its own outage: an operator who
    // cannot read the URLs stops trusting the tool.
    $span = outgoingSpan('https://api.test/v1/charges?limit=2&expand=customer');

    expect($span->attributes()['url.full'])->toBe('https://api.test/v1/charges?limit=2&expand=customer');
});
