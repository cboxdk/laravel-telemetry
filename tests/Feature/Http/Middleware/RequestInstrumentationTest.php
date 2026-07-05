<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\SpanStatus;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\GenericUser;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);

    Route::get('/users/{id}', fn (string $id) => ['id' => $id]);
});

function requestSpans(CollectingExporter $collector): array
{
    $spans = [];

    foreach ($collector->batches() as $batch) {
        foreach ($batch->spans as $span) {
            if ($span->kind === SpanKind::Server) {
                $spans[] = $span;
            }
        }
    }

    return $spans;
}

it('wraps requests in a server span named after the route pattern', function () {
    $this->get('/users/7')->assertOk();

    $spans = requestSpans($this->collector);

    expect($spans)->toHaveCount(1)
        ->and($spans[0]->name)->toBe('GET /users/{id}')
        ->and($spans[0]->attributes()['http.route'])->toBe('/users/{id}')
        ->and($spans[0]->attributes()['http.response.status_code'])->toBe(200)
        ->and($spans[0]->status())->toBe(SpanStatus::Ok);
});

it('records the request duration histogram with low-cardinality labels', function () {
    $this->get('/users/7');
    $this->get('/users/8');

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());

    $sample = $families['http.server.request.duration']->samples[0];

    expect($sample->count)->toBe(2)
        ->and($sample->labels['http.route'])->toBe('/users/{id}')
        ->and($sample->labels['http.response.status_code'])->toBe('200');
});

it('continues an incoming traceparent as a child span', function () {
    $this->get('/users/7', [
        'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
    ]);

    $span = requestSpans($this->collector)[0];

    expect($span->traceId)->toBe('0af7651916cd43dd8448eb211c80319c')
        ->and($span->parentSpanId)->toBe('b7ad6b7169203331');
});

it('does not export when the incoming trace is not sampled', function () {
    $this->get('/users/7', [
        'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-00',
    ]);

    expect(requestSpans($this->collector))->toBeEmpty();
});

it('attributes the request span to the authenticated user', function () {
    $this->actingAs(new GenericUser(['id' => 42]));

    $this->get('/users/7');

    $span = requestSpans($this->collector)[0];

    expect($span->attributes()['enduser.id'])->toBe('42');
});

it('omits user attribution when disabled', function () {
    config()->set('telemetry.instrument.user', false);

    $this->actingAs(new GenericUser(['id' => 42]));

    $this->get('/users/7');

    expect(requestSpans($this->collector)[0]->attributes())->not->toHaveKey('enduser.id');
});

it('marks 5xx responses as errors', function () {
    Route::get('/broken', fn () => throw new RuntimeException('boom'));

    $this->get('/broken');

    $span = collect(requestSpans($this->collector))->firstWhere('name', 'GET /broken');

    expect($span->status())->toBe(SpanStatus::Error)
        ->and($span->attributes()['http.response.status_code'])->toBe(500);
});

it('exposes the trace id in a response header by default', function () {
    $response = $this->get('/users/7');

    $span = requestSpans($this->collector)[0];

    expect($response->headers->get('X-Trace-Id'))->toBe($span->traceId);
});

it('supports a custom header name and disabling it', function () {
    config()->set('telemetry.traces.response_header', 'X-Request-Ref');

    expect($this->get('/users/7')->headers->get('X-Request-Ref'))->toMatch('/^[0-9a-f]{32}$/');

    config()->set('telemetry.traces.response_header', null);

    $response = $this->get('/users/7');

    expect($response->headers->has('X-Trace-Id'))->toBeFalse()
        ->and($response->headers->has('X-Request-Ref'))->toBeFalse();
});

it('publishes the trace id to Laravel Context for error trackers and logs', function () {
    $this->get('/users/7');

    $span = requestSpans($this->collector)[0];

    expect(Context::get('trace_id'))->toBe($span->traceId);
});

it('does not touch Laravel Context when sharing is disabled', function () {
    config()->set('telemetry.traces.share_context', false);

    $this->get('/users/7');

    expect(Context::get('trace_id'))->toBeNull();
});

it('captures domain, client, protocol and query on the request span', function () {
    $this->get('http://api.acme.test/users/7?page=2&token=supersecret&per_page=50', [
        'User-Agent' => 'DemoAgent/1.0',
    ]);

    $attributes = requestSpans($this->collector)[0]->attributes();

    expect($attributes['server.address'])->toBe('api.acme.test')
        ->and($attributes['server.port'])->toBe(80)
        ->and($attributes['client.address'])->toBe('127.0.0.1')
        ->and($attributes['user_agent.original'])->toBe('DemoAgent/1.0')
        ->and($attributes['network.protocol.name'])->toBe('http')
        ->and($attributes['network.protocol.version'])->toBe('1.1')
        ->and($attributes['url.query'])->toBe('page=2&token=REDACTED&per_page=50');
});

it('captures allowlisted headers but never credentials or session material', function () {
    config()->set('telemetry.instrument.request_headers', ['accept', 'authorization', 'cookie', 'x-api-key']);
    config()->set('telemetry.instrument.response_headers', ['content-type']);

    $this->get('/users/7', [
        'Accept' => 'application/json',
        'Authorization' => 'Bearer secret-token',
        'Cookie' => 'session=abc',
        'X-Api-Key' => 'k-123',
    ]);

    $attributes = requestSpans($this->collector)[0]->attributes();

    expect($attributes['http.request.header.accept'])->toBe('application/json')
        ->and($attributes['http.response.header.content_type'])->toContain('application/json')
        ->and($attributes)->not->toHaveKey('http.request.header.authorization')
        ->and($attributes)->not->toHaveKey('http.request.header.cookie')
        ->and($attributes)->not->toHaveKey('http.request.header.x_api_key');
});

it('labels request metrics with the domain, preferring the route domain pattern', function () {
    Route::get('/tenant-home', fn () => 'ok')->domain('{tenant}.acme.test');

    $this->get('http://api.acme.test/users/7');
    $this->get('http://alpha.acme.test/tenant-home');

    $samples = collect(Telemetry::collect())
        ->keyBy(fn ($family) => $family->name())['http.server.request.duration']->samples;

    $byRoute = collect($samples)->keyBy(fn ($sample) => $sample->labels['http.route']);

    expect($byRoute['/users/{id}']->labels['server.address'])->toBe('api.acme.test')
        ->and($byRoute['/tenant-home']->labels['server.address'])->toBe('{tenant}.acme.test');
});

it('omits the domain label when disabled', function () {
    config()->set('telemetry.instrument.host_label', false);

    $this->get('/users/7');

    $sample = collect(Telemetry::collect())
        ->keyBy(fn ($family) => $family->name())['http.server.request.duration']->samples[0];

    expect($sample->labels)->not->toHaveKey('server.address');
});

it('disambiguates users across auth guards', function () {
    config()->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'users']);

    Telemetry::resolveUserUsing(fn ($user, ?string $guard) => ['enduser.plan' => $guard === 'admin' ? 'staff' : 'pro']);

    $this->actingAs(new GenericUser(['id' => 7]), 'admin')->get('/users/7');

    $attributes = requestSpans($this->collector)[0]->attributes();

    expect($attributes['enduser.id'])->toBe('7')
        ->and($attributes['enduser.type'])->toBe('generic_user')
        ->and($attributes['enduser.guard'])->toBe('admin')
        ->and($attributes['enduser.plan'])->toBe('staff');
});

it('attributes login and logout requests via the remembered identity', function () {
    Route::post('/login-then-logout', function () {
        $user = new GenericUser(['id' => 42]);

        event(new Login('web', $user, false));
        event(new Logout('web', $user));

        return response()->noContent();
    });

    $this->post('/login-then-logout');

    $attributes = requestSpans($this->collector)[0]->attributes();

    expect($attributes['enduser.id'])->toBe('42')
        ->and($attributes['enduser.type'])->toBe('generic_user')
        ->and($attributes['enduser.guard'])->toBe('web');
});

it('forgets the remembered identity when context resets', function () {
    event(new Login('web', new GenericUser(['id' => 42]), false));

    Telemetry::resetContext();

    $this->get('/users/7');

    expect(requestSpans($this->collector)[0]->attributes())->not->toHaveKey('enduser.id');
});

it('lets the app name request spans behind catch-all routes', function () {
    Route::get('/{page}', fn (string $page) => 'cms')->where('page', '.*');

    Telemetry::nameRequestsUsing(function ($request, $response) {
        return $response->getStatusCode() === 200 ? 'GET entry:blog' : null;
    });

    $this->get('/some/deep/cms/page');

    $span = requestSpans($this->collector)[0];

    expect($span->name)->toBe('GET entry:blog')
        ->and($span->attributes()['http.route'])->toBe('/{page}');
});

it('lets an instrumentation override the logical route behind a catch-all', function () {
    Route::get('/{page}', fn (string $page) => 'cms')->where('page', '.*');

    Telemetry::resolveRouteUsing(fn ($request, $response) => 'entry:blog');

    $this->get('/some/deep/cms/page');

    $span = requestSpans($this->collector)[0];

    // http.route becomes the logical route; the literal template is kept.
    expect($span->name)->toBe('GET entry:blog')
        ->and($span->attributes()['http.route'])->toBe('entry:blog')
        ->and($span->attributes()['http.route.template'])->toBe('/{page}');

    // The metric label follows — so route tables group by the logical route.
    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());
    $sample = $families['http.server.request.duration']->samples[0];

    expect($sample->labels['http.route'])->toBe('entry:blog');
});

it('keeps the route template when no route resolver returns a value', function () {
    Route::get('/{page}', fn (string $page) => 'cms')->where('page', '.*');

    Telemetry::resolveRouteUsing(fn ($request, $response) => null);

    $this->get('/some/page');

    $span = requestSpans($this->collector)[0];

    expect($span->attributes()['http.route'])->toBe('/{page}')
        ->and($span->attributes())->not->toHaveKey('http.route.template');
});

it('lets nameRequestsUsing and resolveRouteUsing shape name and route independently', function () {
    Route::get('/{page}', fn (string $page) => 'cms')->where('page', '.*');

    Telemetry::nameRequestsUsing(fn () => 'GET Blog article');
    Telemetry::resolveRouteUsing(fn () => 'entry:blog.article');

    $this->get('/some/page');

    $span = requestSpans($this->collector)[0];

    expect($span->name)->toBe('GET Blog article')
        ->and($span->attributes()['http.route'])->toBe('entry:blog.article');
});

it('never clobbers an explicitly renamed request span', function () {
    Route::get('/renaming', function () {
        Telemetry::currentSpan()->updateName('GET custom:name');

        return 'ok';
    });

    Telemetry::nameRequestsUsing(fn () => 'GET resolver:name');

    $this->get('/renaming');

    expect(requestSpans($this->collector)[0]->name)->toBe('GET custom:name');
});

it('enriches request spans at terminate with the final response in hand', function () {
    Telemetry::enrichRequestsUsing(fn ($request, $response) => [
        'app.cache_state' => $response->headers->get('X-Cache-State', 'miss'),
        'app.tenant' => 'acme',
    ]);

    $this->get('/users/7');

    $attributes = requestSpans($this->collector)[0]->attributes();

    expect($attributes['app.cache_state'])->toBe('miss')
        ->and($attributes['app.tenant'])->toBe('acme');
});

it('omits the trace id header on publicly cacheable responses', function () {
    Route::get('/cached-page', fn () => response('ok')->header('Cache-Control', 'public, max-age=3600'));
    Route::get('/cdn-page', fn () => response('ok')->header('Cache-Control', 's-maxage=600'));
    Route::get('/private-page', fn () => response('ok')->header('Cache-Control', 'private, no-store'));

    expect($this->get('/cached-page')->headers->has('X-Trace-Id'))->toBeFalse()
        ->and($this->get('/cdn-page')->headers->has('X-Trace-Id'))->toBeFalse()
        ->and($this->get('/private-page')->headers->get('X-Trace-Id'))->toMatch('/^[0-9a-f]{32}$/');
});

it('tags request spans with the session driver and a hashed session id', function () {
    Route::middleware(StartSession::class)
        ->get('/with-session', fn () => 'ok');

    $this->get('/with-session');

    $attributes = requestSpans($this->collector)[0]->attributes();
    $sessionId = app('session')->getId();

    expect($attributes['session.driver'])->toBe('array')
        ->and($attributes['session.hash'])->toMatch('/^[0-9a-f]{16}$/')
        ->and($attributes['session.hash'])->not->toBe($sessionId)
        ->and(str_contains($sessionId, $attributes['session.hash']))->toBeFalse();
});
