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

/**
 * A Prometheus parse error fails the WHOLE scrape: the target goes up=0 and
 * every metric from the app disappears, not just the offending one. These three
 * are each one line of legal-looking input away.
 */
it('never emits a duplicate label name, however the keys were spelled', function () {
    // The docs tell users to write dotted label keys, so a user label
    // `host.name` alongside the pre-sanitized `host_name` resource label was
    // two distinct array keys that rendered as the same Prometheus name.
    $output = (new PrometheusRenderer)->render(
        [new MetricFamily(
            new MetricDefinition('orders.created', MetricType::Counter, 'Orders created'),
            [new Sample(['host.name' => 'web-1'], 1.0)],
        )],
        ['host_name' => 'db-3'],
    );

    expect(substr_count($output, 'host_name='))->toBe(1);
});

it('never emits one rendered family name twice', function () {
    // MetricDefinition allows `_` in an OTel name, so these are two legal,
    // distinct families that both render as orders_created_total.
    $output = (new PrometheusRenderer)->render([
        new MetricFamily(
            new MetricDefinition('orders.created', MetricType::Counter, 'Dotted'),
            [new Sample(['tenant' => 'a'], 1.0)],
        ),
        new MetricFamily(
            new MetricDefinition('orders_created', MetricType::Counter, 'Underscored'),
            [new Sample(['tenant' => 'b'], 2.0)],
        ),
    ]);

    expect(substr_count($output, '# TYPE orders_created_total'))->toBe(1)
        ->and(substr_count($output, '# HELP orders_created_total'))->toBe(1);
});

it('never emits the same labelset twice when merging families', function () {
    // A stored push gauge and a cross-process observable can share a name AND
    // a labelset; concatenating the samples produced `duplicate sample`.
    $output = (new PrometheusRenderer)->render([
        new MetricFamily(
            new MetricDefinition('queue.depth', MetricType::Gauge, 'Depth'),
            [new Sample(['queue' => 'default'], 1.0)],
        ),
        new MetricFamily(
            new MetricDefinition('queue.depth', MetricType::Gauge, 'Depth'),
            [new Sample(['queue' => 'default'], 2.0)],
        ),
    ]);

    expect(substr_count($output, 'queue_depth{queue="default"}'))->toBe(1);
});

it('drops _total from the family name in OpenMetrics, where the sample keeps it', function () {
    // OpenMetrics: a Counter MetricFamily's name MUST NOT carry _total — the
    // sample carries it. Registering metadata under a name no metric has hides
    // it from Prometheus' metadata API.
    $output = (new PrometheusRenderer)->render(
        [new MetricFamily(
            new MetricDefinition('orders.created', MetricType::Counter, 'Orders created'),
            [new Sample([], 1.0)],
        )],
        [],
        openMetrics: true,
    );

    expect($output)->toContain('# TYPE orders_created counter')
        ->and($output)->toContain('orders_created_total 1')
        ->and($output)->not->toContain('# TYPE orders_created_total');
});
