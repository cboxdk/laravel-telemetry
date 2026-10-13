<?php

declare(strict_types=1);

use Cbox\Telemetry\Http\BrowserSdk;
use Cbox\Telemetry\Http\BrowserSnippet;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Blade;

beforeEach(function () {
    config()->set('telemetry.ingest.spans.enabled', true);
});

/**
 * The <script> tag's attributes, parsed back out of the snippet.
 *
 * @return array<string, string>
 */
function snippetAttributes(string $html): array
{
    preg_match('/<script\s([^>]*)>/', $html, $tag);
    preg_match_all('/([a-z-]+)(?:="([^"]*)")?/', $tag[1] ?? '', $pairs, PREG_SET_ORDER);

    $attributes = [];
    foreach ($pairs as $pair) {
        $attributes[$pair[1]] = html_entity_decode($pair[2] ?? '');
    }

    return $attributes;
}

it('loads the vendored SDK bundle, versioned for cache busting', function () {
    $attributes = snippetAttributes(BrowserSnippet::render());

    expect($attributes['src'])->toBe(url('telemetry/browser.js').'?v='.BrowserSdk::VERSION)
        ->and($attributes)->toHaveKey('defer')
        ->and($attributes['data-endpoint'])->toBe(url('telemetry/spans'));
});

it('keeps a query string already on a custom asset path', function () {
    config()->set('telemetry.ingest.spans.asset_path', 'https://cdn.example.test/rum.js?build=7');

    expect(snippetAttributes(BrowserSnippet::render())['src'])
        ->toBe('https://cdn.example.test/rum.js?build=7&v='.BrowserSdk::VERSION);
});

it('turns every SDK instrumentation on by default', function () {
    $attributes = snippetAttributes(BrowserSnippet::render());

    foreach (['fetch', 'xhr', 'errors', 'vitals', 'navigation', 'frameworks', 'server-timing'] as $switch) {
        expect($attributes['data-'.$switch])->toBe('1');
    }

    expect($attributes['data-sample'])->toBe('1')
        ->and($attributes)->not->toHaveKey('data-debug');
});

it('maps each browser toggle onto the SDK data attribute', function (string $key, string $attribute) {
    config()->set('telemetry.ingest.spans.browser.'.$key, false);

    $attributes = snippetAttributes(BrowserSnippet::render());

    expect($attributes[$attribute])->toBe('0');
})->with([
    ['fetch', 'data-fetch'],
    ['xhr', 'data-xhr'],
    ['errors', 'data-errors'],
    ['vitals', 'data-vitals'],
    ['navigation', 'data-navigation'],
    ['frameworks', 'data-frameworks'],
    ['server_timing', 'data-server-timing'],
]);

it('reads a toggle written as "0" in .env as off', function () {
    config()->set('telemetry.ingest.spans.browser.vitals', '0');

    expect(snippetAttributes(BrowserSnippet::render())['data-vitals'])->toBe('0');
});

it('passes sampling and debug through', function () {
    config()->set('telemetry.ingest.spans.browser.sample', 0.25);
    config()->set('telemetry.ingest.spans.browser.debug', true);

    $attributes = snippetAttributes(BrowserSnippet::render());

    expect($attributes['data-sample'])->toBe('0.25')
        ->and($attributes['data-debug'])->toBe('1');
});

it('stamps the backend service identity and trace header', function () {
    config()->set('telemetry.service.name', 'shop');
    config()->set('telemetry.service.environment', 'staging');
    config()->set('telemetry.service.version', 'a1b2c3d');
    config()->set('telemetry.traces.response_header', 'X-Request-Trace');

    $attributes = snippetAttributes(BrowserSnippet::render());

    expect($attributes['data-service'])->toBe('shop')
        ->and($attributes['data-environment'])->toBe('staging')
        ->and($attributes['data-release'])->toBe('a1b2c3d')
        ->and($attributes['data-trace-header'])->toBe('X-Request-Trace');
});

it('leaves out an unset release, and sends an empty trace header when it is off', function () {
    config()->set('telemetry.service.version', null);
    config()->set('telemetry.traces.response_header', null);

    $html = BrowserSnippet::render();

    expect(snippetAttributes($html))->not->toHaveKey('data-release')
        ->and($html)->toContain('data-trace-header=""');
});

it('escapes configured values', function () {
    config()->set('telemetry.service.name', 'a"><script>alert(1)</script>');

    $html = BrowserSnippet::render();

    expect($html)->not->toContain('<script>alert')
        ->and(snippetAttributes($html)['data-service'])->toBe('a"><script>alert(1)</script>');
});

it('carries the CSP nonce Laravel uses for its own scripts', function () {
    app(Vite::class)->useCspNonce('n0nc3');

    expect(snippetAttributes(BrowserSnippet::render())['nonce'])->toBe('n0nc3');
});

it('has no nonce attribute when the app sets none', function () {
    expect(snippetAttributes(BrowserSnippet::render()))->not->toHaveKey('nonce');
});

it('renders the traceparent meta for the active trace', function () {
    app('telemetry')->tracer()->startSpan('GET /');

    expect(BrowserSnippet::render())->toMatch('/^<meta name="traceparent" content="00-[0-9a-f]{32}-[0-9a-f]{16}-0[01]"><script /');
});

it('renders nothing when telemetry is disabled', function () {
    config()->set('telemetry.enabled', false);

    expect(BrowserSnippet::render())->toBe('')
        ->and(Blade::render('@telemetryBrowser'))->toBe('');
});

it('vendors the bundle its manifest and VERSION describe', function () {
    /** @var array{package: string, version: string, sha256: string, source: array{tag: string}} $manifest */
    $manifest = json_decode((string) file_get_contents(dirname(BrowserSdk::path()).'/browser-sdk.json'), true);
    $bundle = (string) file_get_contents(BrowserSdk::path());

    expect($manifest['package'])->toBe(BrowserSdk::PACKAGE)
        ->and($manifest['version'])->toBe(BrowserSdk::VERSION)
        ->and($manifest['source']['tag'])->toBe('v'.BrowserSdk::VERSION)
        ->and(hash('sha256', $bundle))->toBe($manifest['sha256'])
        ->and($bundle)->toStartWith('/*! '.BrowserSdk::PACKAGE.' '.BrowserSdk::VERSION.' ')
        ->and($bundle)->not->toContain('sourceMappingURL');
});
