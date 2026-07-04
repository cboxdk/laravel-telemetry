<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Support\AnalyticsIdentity;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\SpanKind;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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
