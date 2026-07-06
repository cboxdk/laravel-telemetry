<?php

declare(strict_types=1);

use Cbox\Telemetry\Exporters\Prometheus\PrometheusRenderer;
use Cbox\Telemetry\Metrics\Exemplar;
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

it('suffixes the name with the unit, before _total', function () {
    $output = (new PrometheusRenderer)->render([
        // Byte-valued gauge → _bytes suffix.
        new MetricFamily(
            new MetricDefinition('memory.peak', MetricType::Gauge, unit: 'By'),
            [new Sample([], 1024.0)],
        ),
        // ms counter → unit before _total: <name>_milliseconds_total.
        new MetricFamily(
            new MetricDefinition('job.time', MetricType::Counter, unit: 'ms'),
            [new Sample([], 3.0)],
        ),
        // Unitless stays bare.
        new MetricFamily(
            new MetricDefinition('cache.size', MetricType::Gauge, unit: '1'),
            [new Sample([], 9.0)],
        ),
    ]);

    expect($output)->toContain("memory_peak_bytes 1024\n")
        ->toContain("job_time_milliseconds_total 3\n")
        ->toContain("cache_size 9\n");
});

it('accumulates histogram buckets into cumulative le form', function () {
    $output = (new PrometheusRenderer)->render([
        new MetricFamily(
            new MetricDefinition('req.duration', MetricType::Histogram, unit: 'ms', buckets: [10.0, 100.0]),
            [new HistogramSample(['route' => '/'], [10.0, 100.0], [2, 1, 1], 5065.0, 4)],
        ),
    ]);

    expect($output)
        // Unit 'ms' becomes the '_milliseconds' name suffix (before _bucket/_sum/_count).
        ->toContain('req_duration_milliseconds_bucket{route="/",le="10"} 2')
        ->toContain('req_duration_milliseconds_bucket{route="/",le="100"} 3')
        ->toContain('req_duration_milliseconds_bucket{route="/",le="+Inf"} 4')
        ->toContain('req_duration_milliseconds_sum{route="/"} 5065')
        ->toContain('req_duration_milliseconds_count{route="/"} 4');
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

it('omits exemplars from the classic text format', function () {
    $output = (new PrometheusRenderer)->render([
        new MetricFamily(
            new MetricDefinition('req.duration', MetricType::Histogram, unit: 'ms', buckets: [10.0, 100.0]),
            [new HistogramSample(['route' => '/'], [10.0, 100.0], [2, 1, 1], 5065.0, 4, new Exemplar('abc123', 42.0, 1_700_000_000_000_000_000))],
        ),
    ]);

    expect($output)->not->toContain('trace_id')
        ->not->toContain('# EOF');
});

it('renders an exemplar on the bucket the observation landed in, in OpenMetrics format', function () {
    $output = (new PrometheusRenderer)->render([
        new MetricFamily(
            new MetricDefinition('req.duration', MetricType::Histogram, unit: 'ms', buckets: [10.0, 100.0]),
            [new HistogramSample(['route' => '/'], [10.0, 100.0], [2, 1, 1], 5065.0, 4, new Exemplar('abc123', 42.0, 1_700_000_000_000_000_000))],
        ),
    ], openMetrics: true);

    expect($output)
        ->toContain('req_duration_milliseconds_bucket{route="/",le="10"} 2'."\n")
        ->toContain('req_duration_milliseconds_bucket{route="/",le="100"} 3 # {trace_id="abc123"} 42 1700000000')
        ->toContain('req_duration_milliseconds_bucket{route="/",le="+Inf"} 4'."\n")
        ->toEndWith("# EOF\n");
});

it('renders an exemplar on the +Inf bucket when the value exceeds every bound', function () {
    $output = (new PrometheusRenderer)->render([
        new MetricFamily(
            new MetricDefinition('req.duration', MetricType::Histogram, unit: 'ms', buckets: [10.0, 100.0]),
            [new HistogramSample(['route' => '/'], [10.0, 100.0], [2, 1, 1], 5065.0, 4, new Exemplar('overflow-trace', 999.0, 1_700_000_000_000_000_000))],
        ),
    ], openMetrics: true);

    expect($output)->toContain('req_duration_milliseconds_bucket{route="/",le="+Inf"} 4 # {trace_id="overflow-trace"} 999 1700000000');
});

it('emits # EOF for an empty family list in OpenMetrics format', function () {
    expect((new PrometheusRenderer)->render([], openMetrics: true))->toBe("# EOF\n");
});

it('stamps resource identity labels on every scraped series', function () {
    $renderer = new PrometheusRenderer;

    $family = new MetricFamily(
        new MetricDefinition('http.server.requests', MetricType::Counter, 'reqs', ''),
        [new Sample(['http_route' => '/x'], 5.0)],
    );

    $out = $renderer->render([$family], [
        'service_name' => 'demo-web',
        'deployment_environment_name' => 'production',
        'host_name' => 'web-01',
    ]);

    expect($out)->toContain('service_name="demo-web"')
        ->and($out)->toContain('deployment_environment_name="production"')
        ->and($out)->toContain('host_name="web-01"')
        ->and($out)->toContain('http_route="/x"'); // original sample label preserved
});
