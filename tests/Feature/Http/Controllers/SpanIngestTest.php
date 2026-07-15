<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Http\BrowserSnippet;
use Cbox\Telemetry\Http\Controllers\BrowserAssetController;
use Cbox\Telemetry\Http\Controllers\SpanIngestController;
use Cbox\Telemetry\Http\Middleware\FlushBrowserIngest;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\SpanKind;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Blade;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);
});

function ingest(array $spans, array $config = []): Response
{
    $request = Request::create('/telemetry/spans', 'POST', content: json_encode(['spans' => $spans]));
    $request->headers->set('Content-Type', 'application/json');

    $route = new Route('POST', '/telemetry/spans', []);
    $route->defaults('telemetryIngest', $config + ['enabled' => true, 'max_spans' => 128, 'max_attributes' => 32, 'sample_rate' => 1.0]);
    $request->setRouteResolver(fn () => $route);

    $response = (new SpanIngestController)($request, app(TelemetryManager::class));
    app(FlushBrowserIngest::class)->terminate($request, $response);

    return $response;
}

function ingestedSpans(CollectingExporter $c): array
{
    return collect($c->batches())->flatMap(fn ($b) => $b->spans)->all();
}

function browserSpan(array $over = []): array
{
    $now = (int) (microtime(true) * 1000);

    return array_merge([
        'traceId' => str_repeat('ab12', 8),
        'spanId' => str_repeat('cd34', 4),
        'name' => 'document.load',
        'kind' => 'client',
        'start' => $now - 500,
        'end' => $now,
        'attributes' => ['http.url' => 'https://app.test/dashboard'],
    ], $over);
}

it('defers the export to terminate(), not the controller response', function () {
    $request = Request::create('/telemetry/spans', 'POST', content: json_encode(['spans' => [browserSpan()]]));
    $request->headers->set('Content-Type', 'application/json');

    $route = new Route('POST', '/telemetry/spans', []);
    $route->defaults('telemetryIngest', ['enabled' => true, 'max_spans' => 128, 'max_attributes' => 32, 'sample_rate' => 1.0]);
    $request->setRouteResolver(fn () => $route);

    $response = (new SpanIngestController)($request, app(TelemetryManager::class));

    expect($response->getStatusCode())->toBe(204)
        ->and(ingestedSpans($this->collector))->toBeEmpty();

    app(FlushBrowserIngest::class)->terminate($request, $response);

    expect(ingestedSpans($this->collector))->toHaveCount(1);
});

it('ingests a valid browser span under its own trace id', function () {
    $trace = str_repeat('ab12', 8);
    $response = ingest([browserSpan(['traceId' => $trace])]);

    expect($response->getStatusCode())->toBe(204);

    $spans = ingestedSpans($this->collector);
    expect($spans)->toHaveCount(1)
        ->and($spans[0]->traceId)->toBe($trace)
        ->and($spans[0]->name)->toBe('document.load')
        ->and($spans[0]->kind)->toBe(SpanKind::Client)
        ->and($spans[0]->attributes()['browser'])->toBeTrue()
        ->and($spans[0]->attributes()['http.url'])->toBe('https://app.test/dashboard')
        ->and($spans[0]->durationMs())->toBeGreaterThan(0);
});

it('drops invalid spans but keeps the valid ones in the same batch', function () {
    ingest([
        browserSpan(['traceId' => 'not-hex']),                 // bad trace id
        browserSpan(['spanId' => 'short']),                    // bad span id
        browserSpan(['start' => 0, 'end' => 0]),               // clock-skewed / out of window
        browserSpan(['name' => 'ok.span']),                    // valid
    ]);

    $spans = ingestedSpans($this->collector);
    expect($spans)->toHaveCount(1)->and($spans[0]->name)->toBe('ok.span');
});

it('caps the number of spans and the attributes per span', function () {
    $many = array_fill(0, 500, browserSpan());
    ingest($many, ['max_spans' => 10]);
    expect(ingestedSpans($this->collector))->toHaveCount(10);

    $bigAttrs = [];
    for ($i = 0; $i < 100; $i++) {
        $bigAttrs["k$i"] = "v$i";
    }
    $fresh = new CollectingExporter;
    Telemetry::addExporter($fresh);
    ingest([browserSpan(['attributes' => $bigAttrs])], ['max_attributes' => 5]);

    $attrs = collect($fresh->batches())->flatMap(fn ($b) => $b->spans)->last()->attributes();
    // 5 capped attributes + the stamped 'browser' flag.
    expect(count($attrs))->toBeLessThanOrEqual(6);
});

it('redacts secrets in browser span attributes before export', function () {
    ingest([browserSpan(['attributes' => ['api_key' => 'abc123', 'note' => 'header was Bearer abcdef1234567890abcdef']])]);

    $attrs = ingestedSpans($this->collector)[0]->attributes();
    expect($attrs['api_key'])->toBe('[REDACTED]')
        ->and($attrs['note'])->toBe('header was Bearer [REDACTED]');
});

it('404s when the ingest is disabled', function () {
    expect(fn () => ingest([browserSpan()], ['enabled' => false]))
        ->toThrow(HttpException::class);
});

it('renders the browser snippet (script + config) when ingest is enabled', function () {
    config()->set('telemetry.ingest.spans.enabled', true);

    $html = BrowserSnippet::render();

    expect($html)->toContain('<script')
        ->toContain('browser.js')
        ->toContain('data-endpoint=')
        ->toContain('data-fetch="1"')
        ->toContain('data-errors="1"')
        ->toContain('data-vitals="1"');
});

it('renders nothing when ingest is disabled', function () {
    config()->set('telemetry.ingest.spans.enabled', false);

    expect(BrowserSnippet::render())->toBe('');
});

it('exposes the snippet through the @telemetryBrowser Blade directive', function () {
    config()->set('telemetry.ingest.spans.enabled', true);

    expect(Blade::render('@telemetryBrowser'))
        ->toContain('<script')
        ->toContain('browser.js');
});

it('omits data-session when analytics is off', function () {
    config()->set('telemetry.ingest.spans.enabled', true);
    config()->set('telemetry.analytics.enabled', false);

    expect(BrowserSnippet::render())->not->toContain('data-session');
});

it('propagates the shared session.id via data-session when analytics is on', function () {
    config()->set('telemetry.ingest.spans.enabled', true);
    config()->set('telemetry.analytics.enabled', true);
    config()->set('telemetry.analytics.session.salt', 'test-salt');

    expect(BrowserSnippet::render())->toContain('data-session="');
});

it('serves the zero-build RUM script with cache headers', function () {
    config()->set('telemetry.ingest.spans.enabled', true);

    $res = (new BrowserAssetController)(app(TelemetryManager::class));

    expect($res->getStatusCode())->toBe(200)
        ->and($res->headers->get('Content-Type'))->toContain('javascript')
        ->and($res->headers->get('Cache-Control'))->toContain('max-age')
        ->and($res->getContent())->toContain('traceparent')
        ->and($res->getContent())->toContain('sendBeacon')
        ->and($res->getContent())->toContain('largest-contentful-paint')
        ->and($res->getContent())->toContain('layout-shift');
});

it('404s the RUM script when ingest is disabled', function () {
    config()->set('telemetry.ingest.spans.enabled', false);

    expect(fn () => (new BrowserAssetController)(app(TelemetryManager::class)))
        ->toThrow(HttpException::class);
});

function ingestEventsPayload(array $events, array $config = []): Response
{
    $request = Request::create('/telemetry/spans', 'POST', content: json_encode(['events' => $events]));
    $request->headers->set('Content-Type', 'application/json');

    $route = new Route('POST', '/telemetry/spans', []);
    $route->defaults('telemetryIngest', $config + ['enabled' => true, 'max_spans' => 128, 'max_attributes' => 32, 'sample_rate' => 1.0]);
    $request->setRouteResolver(fn () => $route);

    $response = (new SpanIngestController)($request, app(TelemetryManager::class));
    app(FlushBrowserIngest::class)->terminate($request, $response);

    return $response;
}

function ingestedEvents(CollectingExporter $c): array
{
    return collect($c->batches())->flatMap(fn ($b) => $b->events)->all();
}

it('ingests browser analytics events as unsampled OTLP log records', function () {
    $now = (int) (microtime(true) * 1000);

    $response = ingestEventsPayload([[
        'name' => 'page_view',
        'sessionId' => 'visit-9',
        'traceId' => str_repeat('ab12', 8),
        'time' => $now,
        'attributes' => ['url.path' => '/pricing', 'http.request.header.referer' => 'https://news.ycombinator.com/'],
    ], [
        'name' => 'signup_completed',
        'sessionId' => 'visit-9',
        'time' => $now,
        'attributes' => ['plan' => 'pro'],
    ]]);

    expect($response->getStatusCode())->toBe(204);

    $events = ingestedEvents($this->collector);
    expect($events)->toHaveCount(2);

    $pageView = collect($events)->firstWhere('name', 'analytics.page_view');
    expect($pageView)->not->toBeNull()
        ->and($pageView->attributes['telemetry.stream'])->toBe('analytics')
        ->and($pageView->attributes['analytics.source'])->toBe('browser')
        ->and($pageView->attributes['analytics.event'])->toBe('page_view')
        ->and($pageView->attributes['session.id'])->toBe('visit-9')
        ->and($pageView->attributes['url.path'])->toBe('/pricing')
        ->and($pageView->traceId)->toBe(str_repeat('ab12', 8));

    $signup = collect($events)->firstWhere('name', 'analytics.signup_completed');
    expect($signup)->not->toBeNull()
        ->and($signup->attributes['plan'])->toBe('pro')
        ->and($signup->traceId)->toBeNull();
});

function ingestWith(array $payload, array $headers, array $config = []): Response
{
    $request = Request::create('/telemetry/spans', 'POST', content: json_encode($payload));
    $request->headers->set('Content-Type', 'application/json');

    foreach ($headers as $key => $value) {
        $request->headers->set($key, $value);
    }

    $route = new Route('POST', '/telemetry/spans', []);
    $route->defaults('telemetryIngest', $config + ['enabled' => true, 'max_spans' => 128, 'max_attributes' => 32, 'sample_rate' => 1.0]);
    $request->setRouteResolver(fn () => $route);

    $response = (new SpanIngestController)($request, app(TelemetryManager::class));
    app(FlushBrowserIngest::class)->terminate($request, $response);

    return $response;
}

it('enriches ingested browser spans with server-side Cloudflare geo', function () {
    config()->set('telemetry.analytics.geo.enabled', true);
    Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_FOR);

    ingestWith(['spans' => [browserSpan()]], ['CF-IPCountry' => 'DK']);

    expect(ingestedSpans($this->collector)[0]->attributes()['client.geo.country'])->toBe('DK');

    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
});

it('enriches ingested browser events with server geo and parsed User-Agent', function () {
    config()->set('telemetry.analytics.geo.enabled', true);
    config()->set('telemetry.analytics.user_agent', true);
    Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_FOR);

    ingestWith(
        ['events' => [['name' => 'page_view', 'sessionId' => 'v1', 'time' => (int) (microtime(true) * 1000)]]],
        [
            'CF-IPCountry' => 'DK',
            'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1 Safari/604.1',
        ],
    );

    $attrs = ingestedEvents($this->collector)[0]->attributes;
    expect($attrs['client.geo.country'])->toBe('DK')
        ->and($attrs['user_agent.name'])->toBe('Safari')
        ->and($attrs['os.name'])->toBe('iOS')
        ->and($attrs['device.type'])->toBe('mobile');

    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
});

it('lets server-side geo win over a spoofed client geo attribute', function () {
    config()->set('telemetry.analytics.geo.enabled', true);
    Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_FOR);

    ingestWith(
        ['spans' => [browserSpan(['attributes' => ['client.geo.country' => 'US']])]],
        ['CF-IPCountry' => 'DK'],
    );

    expect(ingestedSpans($this->collector)[0]->attributes()['client.geo.country'])->toBe('DK');

    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
});

it('does not enrich geo from an untrusted ingest origin', function () {
    config()->set('telemetry.analytics.geo.enabled', true);

    ingestWith(['spans' => [browserSpan()]], ['CF-IPCountry' => 'DK']);

    expect(ingestedSpans($this->collector)[0]->attributes())->not->toHaveKey('client.geo.country');
});

it('derives analytics.utm.* + click-id from the browser-sent landing url when analytics.utm is on', function () {
    config()->set('telemetry.analytics.utm', true);

    ingestEventsPayload([[
        'name' => 'page_view',
        'sessionId' => 'v1',
        'time' => (int) (microtime(true) * 1000),
        'attributes' => ['url.full' => 'https://shop.test/land?utm_source=Newsletter&utm_medium=email&gclid=abc123'],
    ]]);

    $attrs = ingestedEvents($this->collector)[0]->attributes;
    expect($attrs['analytics.utm.source'])->toBe('newsletter')
        ->and($attrs['analytics.utm.medium'])->toBe('email')
        ->and($attrs['analytics.click_id'])->toBe('gclid');
});

it('does not derive utm from the landing url when the flag is off', function () {
    config()->set('telemetry.analytics.utm', false);

    ingestEventsPayload([[
        'name' => 'page_view',
        'sessionId' => 'v1',
        'time' => (int) (microtime(true) * 1000),
        'attributes' => ['url.full' => 'https://shop.test/land?utm_source=x&gclid=abc123'],
    ]]);

    $attrs = ingestedEvents($this->collector)[0]->attributes;
    expect($attrs)->not->toHaveKey('analytics.utm.source')
        ->and($attrs)->not->toHaveKey('analytics.click_id');
});

it('drops analytics events with no usable name and caps the count', function () {
    $response = ingestEventsPayload([
        ['name' => 'ok_event', 'time' => (int) (microtime(true) * 1000)],
        ['name' => '!!!', 'time' => 1],       // sanitizes to empty → dropped
        ['name' => 'past_the_cap', 'time' => (int) (microtime(true) * 1000)],
    ], ['max_spans' => 2]);

    expect($response->getStatusCode())->toBe(204);
    // The cap slices the raw input to 2 (like spans); of those the bad name
    // is dropped — so only the valid one in-window survives.
    expect(collect(ingestedEvents($this->collector))->pluck('name')->all())->toBe(['analytics.ok_event']);
});
