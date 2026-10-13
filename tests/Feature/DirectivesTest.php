<?php

declare(strict_types=1);

use Cbox\Telemetry\Directives\Directive;
use Cbox\Telemetry\Directives\DirectiveStore;
use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Http\Controllers\DirectivesController;
use Cbox\Telemetry\Support\SharedState;
use Cbox\Telemetry\Testing\CollectingExporter;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    SharedState::flush();
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);
    Telemetry::directives()->clear();
});

function directiveSpans(CollectingExporter $collector): Collection
{
    return collect($collector->batches())->flatMap(fn ($b) => $b->spans)->values();
}

it('keeps a trace the head decision dropped when a path directive matches', function () {
    Route::get('/admin/users', fn () => 'ok');
    $directive = Telemetry::directives()->add(Directive::path('admin/*'));

    Telemetry::tracer()->resample(false);
    $this->get('/admin/users');

    $span = directiveSpans($this->collector)->firstWhere('name', 'GET /admin/users');

    expect($span)->not->toBeNull()
        ->and($span->attributes()['telemetry.directive'])->toBe($directive->id);
});

it('leaves unmatched traffic to the sample rate', function () {
    Route::get('/public', fn () => 'ok');
    Telemetry::directives()->add(Directive::path('admin/*'));

    Telemetry::tracer()->resample(false);
    $this->get('/public');

    expect(directiveSpans($this->collector)->firstWhere('name', 'GET /public'))->toBeNull();
});

it('matches a route once routing has run', function () {
    Route::get('/checkout/pay', fn () => 'ok')->name('checkout.pay');
    Telemetry::directives()->add(Directive::route('checkout.*'));

    Telemetry::tracer()->resample(false);
    $this->get('/checkout/pay');

    expect(directiveSpans($this->collector)->firstWhere('name', 'GET /checkout/pay'))->not->toBeNull();
});

it('matches a user when the guard authenticates them', function () {
    Route::get('/login-ish', function () {
        auth()->setUser(new GenericUser(['id' => 42]));

        return 'ok';
    });
    Telemetry::directives()->add(Directive::user(42));

    Telemetry::tracer()->resample(false);
    $this->get('/login-ish');

    expect(directiveSpans($this->collector)->firstWhere('name', 'GET /login-ish'))->not->toBeNull();
});

it('still keeps the request span for a user known only at the end', function () {
    Route::get('/me', fn () => 'ok');
    Telemetry::directives()->add(Directive::user(42));

    $this->actingAs(new GenericUser(['id' => 42]));
    // actingAs() fires Authenticated outside any request; start clean.
    Telemetry::resetContext();
    Telemetry::tracer()->resample(false);
    $this->get('/me');

    expect(directiveSpans($this->collector)->firstWhere('name', 'GET /me'))->not->toBeNull();
});

it('matches a context dimension when it is set', function () {
    Telemetry::directives()->add(Directive::context('tenant.id', 7));

    Telemetry::span('job', function () {
        Telemetry::tracer()->resample(false);
        Telemetry::context(['tenant.id' => 7]);
    });
    Telemetry::flush();

    expect(directiveSpans($this->collector)->firstWhere('name', 'job'))->not->toBeNull();
});

it('asks for a profile when the directive says so', function () {
    Telemetry::directives()->add(Directive::job('App\Jobs\*')->profile());
    $asked = 0;
    Telemetry::onProfileRequested(function () use (&$asked) {
        $asked++;
    });

    Telemetry::span('job', function () {
        Telemetry::applyDirectives(['job' => 'App\Jobs\Sync']);
        Telemetry::applyDirectives(['job' => 'App\Jobs\Sync']);
    });

    expect($asked)->toBe(1)
        ->and(Telemetry::profilingRequested())->toBeTrue();
});

it('drops expired directives and caps how many there can be', function () {
    $store = new DirectiveStore(fn () => app('cache')->store(), max: 2, maxMinutes: 10);

    $store->add(Directive::user(1));
    $store->add(Directive::user(2));

    expect($store->all())->toHaveCount(2)
        ->and($store->all()[0]->expiresAt)->toBeLessThanOrEqual(time() + 600);

    expect(fn () => $store->add(Directive::user(3)))->toThrow(InvalidArgumentException::class);
    expect(fn () => $store->add(Directive::user(4)->until(time() - 1)))->toThrow(InvalidArgumentException::class);

    expect($store->remove($store->all()[0]->id))->toBeTrue()
        ->and($store->all())->toHaveCount(1);
});

it('remembers an empty list instead of reading the cache every time', function () {
    $reads = 0;
    $cache = app('cache')->store();
    $store = new DirectiveStore(function () use ($cache, &$reads) {
        $reads++;

        return $cache;
    });

    $store->active();
    $store->forgetLoaded();
    $store->active();

    expect($reads)->toBe(1);

    // Adding here is seen here at once.
    $store->add(Directive::user(1));

    expect($store->active())->toHaveCount(1);
});

it('manages directives from artisan', function () {
    $this->artisan('telemetry:watch', ['--user' => '42', '--minutes' => 30, '--profile' => true, '--reason' => 'ticket'])
        ->assertSuccessful();

    $directive = Telemetry::directives()->all()[0];

    expect($directive->match)->toBe('user')
        ->and($directive->profile)->toBeTrue()
        ->and($directive->createdBy)->toBe('artisan');

    $this->artisan('telemetry:watch', ['--list' => true])->expectsOutputToContain($directive->id)->assertSuccessful();
    $this->artisan('telemetry:watch', ['--remove' => $directive->id])->assertSuccessful();

    expect(Telemetry::directives()->all())->toBe([]);
});

function signedDirectiveCall($test, string $method, string $uri, array $body = [], ?int $timestamp = null, string $secret = 's3cret', ?string $signature = null)
{
    $raw = $body === [] ? '' : json_encode($body);
    $timestamp ??= time();
    $id = preg_match('#/telemetry/directives/(.+)$#', $uri, $m) === 1 ? $m[1] : '';
    $signature ??= DirectivesController::signature($secret, (string) $timestamp, $method, $id, $raw);

    return $test->call($method, $uri, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_TELEMETRY_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_TELEMETRY_SIGNATURE' => $signature,
    ], $raw);
}

describe('signed endpoint', function () {
    beforeEach(function () {
        config()->set('telemetry.directives.secret', 's3cret');
        Route::get('telemetry/directives', [DirectivesController::class, 'index']);
        Route::post('telemetry/directives', [DirectivesController::class, 'store']);
        Route::delete('telemetry/directives/{id}', [DirectivesController::class, 'destroy']);
    });

    it('adds, lists and removes directives', function () {
        $created = signedDirectiveCall($this, 'POST', '/telemetry/directives', [
            'id' => 'chosen-by-caller', 'match' => 'route', 'value' => 'checkout.*', 'minutes' => 15, 'profile' => true, 'created_by' => 'insights',
        ])->assertCreated()->json('directive');

        expect($created['id'])->not->toBe('chosen-by-caller')
            ->and($created['expires_at'])->toBeLessThanOrEqual(time() + 900)
            ->and($created['created_by'])->toBe('insights');

        signedDirectiveCall($this, 'GET', '/telemetry/directives')->assertOk()->assertJsonPath('directives.0.id', $created['id']);
        signedDirectiveCall($this, 'DELETE', '/telemetry/directives/'.$created['id'])->assertNoContent();
        // A fresh signature: the same one twice is a replay (see below).
        signedDirectiveCall($this, 'DELETE', '/telemetry/directives/'.$created['id'], timestamp: time() + 1)->assertNotFound();
    });

    it('rejects a bad directive', function () {
        signedDirectiveCall($this, 'POST', '/telemetry/directives', ['match' => 'planet', 'value' => 'mars', 'minutes' => 5])
            ->assertStatus(422);
    });

    it('rejects unsigned, wrongly signed and stale calls', function () {
        $this->getJson('/telemetry/directives')->assertUnauthorized();
        signedDirectiveCall($this, 'GET', '/telemetry/directives', secret: 'wrong')->assertUnauthorized();
        signedDirectiveCall($this, 'GET', '/telemetry/directives', timestamp: time() - 600)->assertUnauthorized();
    });

    it('accepts each signature once', function () {
        $timestamp = time();
        $signature = DirectivesController::signature('s3cret', (string) $timestamp, 'GET', '', '');

        signedDirectiveCall($this, 'GET', '/telemetry/directives', timestamp: $timestamp, signature: $signature)->assertOk();
        signedDirectiveCall($this, 'GET', '/telemetry/directives', timestamp: $timestamp, signature: $signature)->assertUnauthorized();
    });

    it('binds the method and the id, so a list call cannot be replayed as a delete', function () {
        $created = signedDirectiveCall($this, 'POST', '/telemetry/directives', ['match' => 'user', 'value' => '1', 'minutes' => 5])->json('directive');
        $timestamp = time();
        $listSignature = DirectivesController::signature('s3cret', (string) $timestamp, 'GET', '', '');

        signedDirectiveCall($this, 'DELETE', '/telemetry/directives/'.$created['id'], timestamp: $timestamp, signature: $listSignature)
            ->assertUnauthorized();

        expect(Telemetry::directives()->all())->toHaveCount(1);
    });

    it('refuses everything when no secret is configured', function () {
        config()->set('telemetry.directives.secret', null);

        signedDirectiveCall($this, 'GET', '/telemetry/directives')->assertUnauthorized();
    });
});
