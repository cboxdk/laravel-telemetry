<?php

declare(strict_types=1);

use Cbox\Telemetry\Exporters\Prometheus\PrometheusRenderer;
use Cbox\Telemetry\Metrics\HistogramSample;
use Cbox\Telemetry\Metrics\MetricDefinition;
use Cbox\Telemetry\Metrics\MetricFamily;
use Cbox\Telemetry\Metrics\MetricType;
use Cbox\Telemetry\Metrics\Sample;

it('renders counters with the _total suffix and dot-to-underscore names', function () {
    $output = (new PrometheusRenderer)->render([
        new MetricFamily(
            new MetricDefinition('orders.created', MetricType::Counter, 'Orders created'),
            [new Sample(['tenant' => 'acme'], 42.0)],
        ),
    ]);

    expect($output)->toBe(<<<'TXT'
# HELP orders_created_total Orders created
# TYPE orders_created_total counter
orders_created_total{tenant="acme"} 42

TXT);
});

it('renders gauges without labels', function () {
    $output = (new PrometheusRenderer)->render([
        new MetricFamily(
            new MetricDefinition('queue.depth', MetricType::Gauge),
            [new Sample([], 7.5)],
        ),
    ]);

    expect($output)->toContain('# TYPE queue_depth gauge')
        ->toContain("queue_depth 7.5\n");
});

it('accumulates histogram buckets into cumulative le form', function () {
    $output = (new PrometheusRenderer)->render([
        new MetricFamily(
            new MetricDefinition('req.duration', MetricType::Histogram, unit: 'ms', buckets: [10.0, 100.0]),
            [new HistogramSample(['route' => '/'], [10.0, 100.0], [2, 1, 1], 5065.0, 4)],
        ),
    ]);

    expect($output)
        ->toContain('req_duration_bucket{route="/",le="10"} 2')
        ->toContain('req_duration_bucket{route="/",le="100"} 3')
        ->toContain('req_duration_bucket{route="/",le="+Inf"} 4')
        ->toContain('req_duration_sum{route="/"} 5065')
        ->toContain('req_duration_count{route="/"} 4');
});

it('escapes label values', function () {
    $output = (new PrometheusRenderer)->render([
        new MetricFamily(
            new MetricDefinition('log.lines', MetricType::Counter),
            [new Sample(['message' => "with \"quotes\" and \\slashes\\ and\nnewlines"], 1.0)],
        ),
    ]);

    expect($output)->toContain('message="with \"quotes\" and \\\\slashes\\\\ and\nnewlines"');
});

it('renders nothing for an empty family list', function () {
    expect((new PrometheusRenderer)->render([]))->toBe('');
});
