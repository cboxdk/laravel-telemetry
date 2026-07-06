<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Tracing\Tracer;

it('serves the scrape endpoint', function () {
    Telemetry::counter('orders.created', 'Orders created')->inc(3, ['tenant' => 'acme']);
    Telemetry::gauge('queue.depth', fn () => 42.0);

    $response = $this->get('/telemetry/metrics');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');

    // Every series is stamped with the resource identity (service_name,
    // deployment_environment_name, host_name) so a shared Prometheus can
    // tell apps/hosts apart — alongside the metric's own labels.
    expect($response->getContent())
        ->toContain('orders_created_total{')
        ->toContain('service_name="laravel"')
        ->toContain('tenant="acme"')
        ->toContain('queue_depth{')
        ->toMatch('/orders_created_total\{[^}]*\} 3/')
        ->toMatch('/queue_depth\{[^}]*\} 42/');
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

it('is closed by default outside local/testing with no allowlist or token', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->get('/telemetry/metrics')->assertForbidden();
});

it('stays open in local without an allowlist or token', function () {
    app()->detectEnvironment(fn () => 'local');

    $this->get('/telemetry/metrics')->assertOk();
});

it('accepts the configured bearer token outside local/testing', function () {
    app()->detectEnvironment(fn () => 'production');
    config()->set('telemetry.prometheus.token', 'scrape-secret');

    $this->withHeader('Authorization', 'Bearer scrape-secret')
        ->get('/telemetry/metrics')
        ->assertOk();
});

it('rejects a wrong bearer token outside local/testing', function () {
    app()->detectEnvironment(fn () => 'production');
    config()->set('telemetry.prometheus.token', 'scrape-secret');

    $this->withHeader('Authorization', 'Bearer wrong')
        ->get('/telemetry/metrics')
        ->assertForbidden();
});

it('serves classic text format without exemplars by default', function () {
    $span = app(Tracer::class)->startSpan('checkout');
    Telemetry::histogram('checkout.duration', unit: 'ms')->record(5.0);
    $span->end();

    $response = $this->get('/telemetry/metrics');

    $response->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
    expect($response->getContent())->not->toContain('trace_id');
});

it('serves OpenMetrics with an exemplar when the client requests it', function () {
    $span = app(Tracer::class)->startSpan('checkout');
    $traceId = $span->traceId;
    Telemetry::histogram('checkout.duration', unit: 'ms')->record(5.0);
    $span->end();

    $response = $this->withHeader('Accept', 'application/openmetrics-text;version=1.0.0;q=0.5,text/plain;q=0.1')
        ->get('/telemetry/metrics');

    $response->assertHeader('Content-Type', 'application/openmetrics-text; version=1.0.0; charset=utf-8');
    expect($response->getContent())
        ->toContain('checkout_duration_milliseconds_bucket')
        ->toContain('trace_id="'.$traceId.'"')
        ->toEndWith("# EOF\n");
});
