<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;

it('serves the scrape endpoint', function () {
    Telemetry::counter('orders.created', 'Orders created')->inc(3, ['tenant' => 'acme']);
    Telemetry::gauge('queue.depth', fn () => 42.0);

    $response = $this->get('/telemetry/metrics');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');

    expect($response->getContent())
        ->toContain('orders_created_total{tenant="acme"} 3')
        ->toContain('queue_depth 42');
});

it('respects the endpoint metric filter', function () {
    config()->set('telemetry.prometheus.endpoints.default.only', ['queue']);

    Telemetry::counter('orders.created')->inc();
    Telemetry::gauge('queue.depth', fn () => 42.0);

    $content = $this->get('/telemetry/metrics')->getContent();

    expect($content)->toContain('queue_depth')
        ->not->toContain('orders_created');
});

it('blocks disallowed ips', function () {
    config()->set('telemetry.prometheus.allowed_ips', ['10.0.0.0/8']);

    $this->get('/telemetry/metrics')->assertForbidden();
});

it('allows allowlisted ips', function () {
    config()->set('telemetry.prometheus.allowed_ips', ['127.0.0.1']);

    $this->get('/telemetry/metrics')->assertOk();
});

it('returns 404 when prometheus is disabled at runtime', function () {
    config()->set('telemetry.prometheus.enabled', false);

    $this->get('/telemetry/metrics')->assertNotFound();
});
