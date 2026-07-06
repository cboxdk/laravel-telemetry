<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Support\AnalyticsIdentity;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\SpanKind;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

afterEach(fn () => TrustProxies::flushState());

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);
    Route::get('/p', fn () => 'ok');
});

function analyticsServerSpan(CollectingExporter $collector)
{
    foreach ($collector->batches() as $batch) {
        foreach ($batch->spans as $span) {
            if ($span->kind === SpanKind::Server) {
                return $span;
            }
        }
    }

    return null;
}

it('stamps NO session.id when analytics is off (zero diff)', function () {
    config()->set('telemetry.analytics.enabled', false);

    $this->get('/p')->assertOk();

    expect(analyticsServerSpan($this->collector)->attributes())->not->toHaveKey('session.id');
});

it('stamps a session.id when analytics is on', function () {
    config()->set('telemetry.analytics.enabled', true);
    config()->set('telemetry.analytics.session.salt', 'test-salt');

    $this->get('/p')->assertOk();

    $sid = analyticsServerSpan($this->collector)->attributes()['session.id'] ?? null;
    expect($sid)->toBeString()->toHaveLength(32);
});

it('lets a hook override the session.id (e.g. Cloudflare CF-Ray)', function () {
    config()->set('telemetry.analytics.enabled', true);
    Telemetry::resolveSessionUsing(fn (Request $r) => $r->header('CF-Ray') ?: null);

    $this->get('/p', ['CF-Ray' => 'abc123-CPH'])->assertOk();

    expect(analyticsServerSpan($this->collector)->attributes()['session.id'])->toBe('abc123-CPH');
});

it('falls back to the cookieless default when the hook returns null', function () {
    config()->set('telemetry.analytics.enabled', true);
    config()->set('telemetry.analytics.session.salt', 'test-salt');
    Telemetry::resolveSessionUsing(fn (Request $r) => null);

    $this->get('/p')->assertOk();

    expect(analyticsServerSpan($this->collector)->attributes()['session.id'])->toHaveLength(32);
});

it('stamps client.geo.* from a geo hook (e.g. CF-IPCountry)', function () {
    config()->set('telemetry.analytics.enabled', true);
    Telemetry::resolveClientGeoUsing(fn (Request $r) => array_filter([
        'client.geo.country' => $r->header('CF-IPCountry'),
    ]));

    $this->get('/p', ['CF-IPCountry' => 'DK'])->assertOk();

    expect(analyticsServerSpan($this->collector)->attributes()['client.geo.country'])->toBe('DK');
});

it('stamps client.geo.country from the built-in Cloudflare header when trusted', function () {
    config()->set('telemetry.analytics.enabled', true);
    config()->set('telemetry.analytics.geo.enabled', true);
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
    TrustProxies::at('127.0.0.1');

    $this->get('/p', ['CF-IPCountry' => 'DK'])->assertOk();

    expect(analyticsServerSpan($this->collector)->attributes()['client.geo.country'])->toBe('DK');
});

it('does not trust the Cloudflare header from an untrusted origin', function () {
    config()->set('telemetry.analytics.enabled', true);
    config()->set('telemetry.analytics.geo.enabled', true);

    $this->get('/p', ['CF-IPCountry' => 'DK'])->assertOk();

    expect(analyticsServerSpan($this->collector)->attributes())->not->toHaveKey('client.geo.country');
});

it('lets a geo hook win over the built-in Cloudflare header', function () {
    config()->set('telemetry.analytics.enabled', true);
    config()->set('telemetry.analytics.geo.enabled', true);
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
    TrustProxies::at('127.0.0.1');
    Telemetry::resolveClientGeoUsing(fn () => ['client.geo.country' => 'ZZ']);

    $this->get('/p', ['CF-IPCountry' => 'DK'])->assertOk();

    expect(analyticsServerSpan($this->collector)->attributes()['client.geo.country'])->toBe('ZZ');
});

it('computes a cookieless session id that is stable per day and rotates', function () {
    $req = Request::create('https://shop.test/x', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'UA/1']);

    $mon = AnalyticsIdentity::cookielessSession($req, 'salt', '2026-07-04');
    $monAgain = AnalyticsIdentity::cookielessSession($req, 'salt', '2026-07-04');
    $tue = AnalyticsIdentity::cookielessSession($req, 'salt', '2026-07-05');
    $otherSalt = AnalyticsIdentity::cookielessSession($req, 'other', '2026-07-04');

    expect($mon)->toHaveLength(32)
        ->toBe($monAgain)          // deterministic within a day
        ->not->toBe($tue)          // rotates at midnight
        ->not->toBe($otherSalt);   // salt matters
});

function analyticsEvents(CollectingExporter $collector, string $name): array
{
    $events = [];
    foreach ($collector->batches() as $batch) {
        foreach ($batch->events as $event) {
            if ($event->name === $name) {
                $events[] = $event;
            }
        }
    }

    return $events;
}

it('emits an unsampled analytics.page_view event for an HTML GET', function () {
    config()->set('telemetry.analytics.enabled', true);
    config()->set('telemetry.analytics.session.salt', 'test-salt');
    Route::get('/page', fn () => response('<html>hi</html>')->header('Content-Type', 'text/html'));

    $this->get('/page', ['referer' => 'https://google.com/'])->assertOk();

    $events = analyticsEvents($this->collector, 'analytics.page_view');
    expect($events)->toHaveCount(1);

    $attrs = $events[0]->attributes;
    expect($attrs['telemetry.stream'])->toBe('analytics')
        ->and($attrs['analytics.source'])->toBe('server')
        ->and($attrs['url.path'])->toBe('/page')
        ->and($attrs['http.response.status_code'])->toBe(200)
        ->and($attrs['http.request.header.referer'])->toBe('https://google.com/')
        ->and($attrs['session.id'])->toHaveLength(32)
        ->and($events[0]->traceId)->not->toBeNull();
});

it('does not emit a page_view when analytics is off', function () {
    config()->set('telemetry.analytics.enabled', false);
    Route::get('/page', fn () => response('<html>hi</html>')->header('Content-Type', 'text/html'));

    $this->get('/page')->assertOk();

    expect(analyticsEvents($this->collector, 'analytics.page_view'))->toBeEmpty();
});

it('does not count JSON/XHR or non-GET as page views', function () {
    config()->set('telemetry.analytics.enabled', true);
    Route::get('/api', fn () => response()->json(['ok' => true]));
    Route::get('/page', fn () => response('<html>hi</html>')->header('Content-Type', 'text/html'));

    $this->getJson('/api')->assertOk();                               // JSON response
    $this->get('/page', ['X-Requested-With' => 'XMLHttpRequest']);    // AJAX

    expect(analyticsEvents($this->collector, 'analytics.page_view'))->toBeEmpty();
});

it('can disable page_view events while keeping session.id', function () {
    config()->set('telemetry.analytics.enabled', true);
    config()->set('telemetry.analytics.page_views', false);
    Route::get('/page', fn () => response('<html>hi</html>')->header('Content-Type', 'text/html'));

    $this->get('/page')->assertOk();

    expect(analyticsEvents($this->collector, 'analytics.page_view'))->toBeEmpty()
        ->and(analyticsServerSpan($this->collector)->attributes())->toHaveKey('session.id');
});

it('stamps parsed user_agent.* when analytics.user_agent is on', function () {
    config()->set('telemetry.analytics.enabled', true);
    config()->set('telemetry.analytics.user_agent', true);
    Route::get('/ua', fn () => response('<html></html>')->header('Content-Type', 'text/html'));

    $this->get('/ua', ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36']);

    $attrs = analyticsServerSpan($this->collector)->attributes();
    expect($attrs['user_agent.name'])->toBe('Chrome')
        ->and($attrs['os.name'])->toBe('Windows')
        ->and($attrs['device.type'])->toBe('desktop');
});

it('does not parse the UA when the toggle is off', function () {
    config()->set('telemetry.analytics.enabled', true);
    config()->set('telemetry.analytics.user_agent', false);
    Route::get('/ua', fn () => response('<html></html>')->header('Content-Type', 'text/html'));

    $this->get('/ua', ['User-Agent' => 'Mozilla/5.0 Chrome/120.0']);

    expect(analyticsServerSpan($this->collector)->attributes())->not->toHaveKey('user_agent.name');
});
