<?php

declare(strict_types=1);

use Cbox\Telemetry\Directives\Directive;

it('matches a route by name or URI template', function () {
    $directive = Directive::route('checkout.*');

    expect($directive->matches(['route' => 'checkout.pay']))->toBeTrue()
        ->and($directive->matches(['route' => 'cart.show']))->toBeFalse()
        ->and(Directive::route('api/orders/*')->matches(['route' => null, 'route.uri' => 'api/orders/{order}']))->toBeTrue()
        // the http_route label form, root route included
        ->and(Directive::route('/api/orders/{order}')->matches(['route.uri' => 'api/orders/{order}']))->toBeTrue()
        ->and(Directive::route('/')->matches(['route.uri' => '/']))->toBeTrue()
        ->and(Directive::route('/')->matches(['route.uri' => 'about']))->toBeFalse()
        ->and(Directive::route('livewire:counter')->matches(['route.logical' => 'livewire:counter']))->toBeTrue();
});

it('matches paths, jobs, users and context dimensions', function () {
    expect(Directive::path('/admin/*')->matches(['path' => 'admin/users']))->toBeTrue()
        ->and(Directive::job('App\Jobs\Billing\*')->matches(['job' => 'App\Jobs\Billing\Sync']))->toBeTrue()
        ->and(Directive::user(42)->matches(['user' => '42']))->toBeTrue()
        ->and(Directive::user(42)->matches(['user' => '420']))->toBeFalse()
        ->and(Directive::context('tenant.id', 7)->matches(['context:tenant.id' => 7]))->toBeTrue()
        ->and(Directive::context('tenant.id', 7)->matches(['context:tenant.id' => 8]))->toBeFalse()
        ->and(Directive::user(42)->matches([]))->toBeFalse();
});

it('expires, an hour by default', function () {
    $directive = Directive::user(1);

    expect($directive->expiresAt)->toBeGreaterThan(time() + 3500)
        ->and($directive->for(minutes: 5)->expiresAt)->toBeLessThanOrEqual(time() + 300)
        ->and($directive->until(time() - 1)->expired())->toBeTrue();
});

it('round-trips through an array', function () {
    $directive = Directive::context('tenant.id', 7)->profile()->because('ticket 881')->by('alice');

    expect(Directive::fromArray($directive->toArray()))->toEqual($directive);
});

it('refuses what it cannot match on', function (Closure $make) {
    $make();
})->throws(InvalidArgumentException::class)->with([
    'unknown kind' => [fn () => new Directive('x', 'planet', null, 'mars')],
    'context without key' => [fn () => new Directive('x', 'context', null, '7')],
    'empty value' => [fn () => Directive::path('')],
]);
