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
        ->toContain('req_duration_milliseconds_bucket{le="10",route="/"} 2')
        ->toContain('req_duration_milliseconds_bucket{le="100",route="/"} 3')
        ->toContain('req_duration_milliseconds_bucket{le="+Inf",route="/"} 4')
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
        ->toContain('req_duration_milliseconds_bucket{le="10",route="/"} 2'."\n")
        ->toContain('req_duration_milliseconds_bucket{le="100",route="/"} 3 # {trace_id="abc123"} 42 1700000000')
        ->toContain('req_duration_milliseconds_bucket{le="+Inf",route="/"} 4'."\n")
        ->toEndWith("# EOF\n");
});

it('renders an exemplar on the +Inf bucket when the value exceeds every bound', function () {
    $output = (new PrometheusRenderer)->render([
        new MetricFamily(
            new MetricDefinition('req.duration', MetricType::Histogram, unit: 'ms', buckets: [10.0, 100.0]),
            [new HistogramSample(['route' => '/'], [10.0, 100.0], [2, 1, 1], 5065.0, 4, new Exemplar('overflow-trace', 999.0, 1_700_000_000_000_000_000))],
        ),
    ], openMetrics: true);

    expect($output)->toContain('req_duration_milliseconds_bucket{le="+Inf",route="/"} 4 # {trace_id="overflow-trace"} 999 1700000000');
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

it('never declares one family name with two types', function () {
    // A counter writes its samples as `jobs_total` but its OpenMetrics metadata
    // as `jobs`, so keying dedupe on the sample name alone let a counter `jobs`
    // and a gauge `jobs` through — and both declared `# TYPE jobs`.
    $output = (new PrometheusRenderer)->render([
        new MetricFamily(new MetricDefinition('jobs', MetricType::Counter, 'Counter'), [new Sample([], 1.0)]),
        new MetricFamily(new MetricDefinition('jobs', MetricType::Gauge, 'Gauge'), [new Sample([], 2.0)]),
    ], [], openMetrics: true);

    expect(substr_count($output, '# TYPE jobs '))->toBe(1);
});

it('refuses to merge two families whose units disagree', function () {
    // `latency` in seconds and `latency_seconds` in microseconds render to one
    // name; merging them would report microseconds under a seconds suffix.
    $output = (new PrometheusRenderer)->render([
        new MetricFamily(new MetricDefinition('latency', MetricType::Gauge, 'Seconds', 's'), [new Sample(['a' => '1'], 1.0)]),
        new MetricFamily(new MetricDefinition('latency_seconds', MetricType::Gauge, 'Micros', 'us'), [new Sample(['a' => '2'], 999.0)]),
    ]);

    expect(substr_count($output, '# TYPE latency_seconds'))->toBe(1)
        ->and($output)->not->toContain('999');
});

it('renders one labelset once however its keys were ordered', function () {
    // A stored gauge and a cross-process observable can carry the same labels
    // in a different order; both rendered as the same series line.
    $output = (new PrometheusRenderer)->render([
        new MetricFamily(new MetricDefinition('queue.depth', MetricType::Gauge, 'Depth'),
            [new Sample(['queue' => 'default', 'host' => 'web'], 1.0)]),
        new MetricFamily(new MetricDefinition('queue.depth', MetricType::Gauge, 'Depth'),
            [new Sample(['host' => 'web', 'queue' => 'default'], 2.0)]),
    ]);

    expect(substr_count($output, 'queue_depth{'))->toBe(1);
});

it('deduplicates a labelset inside a single family', function () {
    // Sample dedupe previously ran only when two families were merged, so a
    // family carrying both spellings of one labelset was never checked.
    $output = (new PrometheusRenderer)->render([
        new MetricFamily(new MetricDefinition('queue.depth', MetricType::Gauge, 'Depth'), [
            new Sample(['host.name' => 'a'], 1.0),
            new Sample(['host_name' => 'a'], 2.0),
        ]),
    ]);

    expect(substr_count($output, 'queue_depth{host_name="a"}'))->toBe(1);
});

it('keeps a counter and a gauge that only collide in OpenMetrics', function () {
    // Classic format writes the counter as `work_items_total` and the gauge as
    // `work_items` — distinct names, so dropping one was a regression. In
    // OpenMetrics the counter's METADATA is `work_items`, and then they do
    // collide.
    $families = [
        new MetricFamily(new MetricDefinition('work.items', MetricType::Counter, 'Counter'), [new Sample([], 1.0)]),
        new MetricFamily(new MetricDefinition('work_items', MetricType::Gauge, 'Gauge'), [new Sample([], 2.0)]),
    ];

    $classic = (new PrometheusRenderer)->render($families);

    expect($classic)->toContain('# TYPE work_items_total counter')
        ->and($classic)->toContain('# TYPE work_items gauge');

    $open = (new PrometheusRenderer)->render($families, [], openMetrics: true);

    expect(substr_count($open, '# TYPE work_items '))->toBe(1);
});

it('detects a collision on a histogram suffix', function () {
    // A histogram `payload` writes payload_bucket/_sum/_count, so a gauge
    // `payload.count` collides with it even though neither of their own
    // family names match.
    $output = (new PrometheusRenderer)->render([
        new MetricFamily(new MetricDefinition('payload', MetricType::Histogram, 'Sizes', '', [10.0]),
            [new HistogramSample([], [10.0], [1, 0], 5.0, 1)]),
        new MetricFamily(new MetricDefinition('payload.count', MetricType::Gauge, 'Count'), [new Sample([], 9.0)]),
    ]);

    // Asserting only that the gauge is gone would also pass an implementation
    // that dropped BOTH participants, so the histogram's own output is
    // asserted in full: first one wins, it is not a mutual annihilation.
    expect(substr_count($output, '# TYPE payload_count'))->toBe(0)
        ->and($output)->not->toContain(' 9')
        ->and($output)->toContain('# TYPE payload histogram')
        ->and($output)->toContain('payload_bucket{le="10"} 1')
        ->and($output)->toContain('payload_bucket{le="+Inf"} 1')
        ->and($output)->toContain('payload_sum 5')
        ->and($output)->toContain('payload_count 1');
});

it('merges families whose units are different spellings of one unit', function () {
    // `By` and `bytes` both suffix `_bytes`, so the two families render under
    // one name. Comparing the raw strings called them incompatible and dropped
    // the second family's samples entirely.
    $output = (new PrometheusRenderer)->render([
        new MetricFamily(new MetricDefinition('cache.size', MetricType::Gauge, 'Size', 'By'),
            [new Sample(['cache' => 'a'], 1.0)]),
        new MetricFamily(new MetricDefinition('cache_size', MetricType::Gauge, 'Size', 'bytes'),
            [new Sample(['cache' => 'b'], 2.0)]),
    ]);

    expect(substr_count($output, '# TYPE cache_size_bytes gauge'))->toBe(1)
        ->and($output)->toContain('cache_size_bytes{cache="a"} 1')
        ->and($output)->toContain('cache_size_bytes{cache="b"} 2');
});

it('treats an empty label value as an absent label', function () {
    // Prometheus defines `foo{route=""}` and `foo` as the SAME series, so
    // emitting both lines cost one of the two values at ingestion.
    $output = (new PrometheusRenderer)->render([
        new MetricFamily(new MetricDefinition('requests', MetricType::Gauge, 'Requests'), [
            new Sample([], 1.0),
            new Sample(['route' => ''], 2.0),
        ]),
    ]);

    expect(substr_count($output, "\nrequests"))->toBe(1)
        ->and($output)->not->toContain('route=""');
});

it('reserves the OpenMetrics _created suffix for counters and histograms', function () {
    // OpenMetrics lets a counter carry an optional `<name>_created` sample, so
    // a gauge named `<name>.created` is a forbidden clash even though this
    // renderer never emits that sample. Classic text reserves nothing.
    $families = [
        new MetricFamily(new MetricDefinition('jobs', MetricType::Counter, 'Jobs'), [new Sample([], 1.0)]),
        new MetricFamily(new MetricDefinition('jobs.created', MetricType::Gauge, 'Created'), [new Sample([], 9.0)]),
    ];

    $open = (new PrometheusRenderer)->render($families, [], openMetrics: true);

    expect($open)->not->toContain('jobs_created')
        ->and($open)->toContain('# TYPE jobs counter');

    expect((new PrometheusRenderer)->render($families))->toContain('# TYPE jobs_created gauge');
});

it('renders a large family without quadratic collision checking', function () {
    // Every family used to be intersected against the whole accumulated name
    // list, so this grew with the SQUARE of the family count: 10 000 gauges
    // measured 3.6 s against 19 ms here — long enough to blow a scrape timeout
    // on an app with many series. The threshold is two orders of magnitude
    // above the linear cost, so it catches a return of the quadratic term
    // without turning CI timing noise into a failure.
    $families = [];

    for ($i = 0; $i < 10_000; $i++) {
        $families[] = new MetricFamily(
            new MetricDefinition("metric.n{$i}", MetricType::Gauge, 'N'),
            [new Sample([], (float) $i)],
        );
    }

    $started = microtime(true);
    $output = (new PrometheusRenderer)->render($families);
    $elapsed = microtime(true) - $started;

    expect(substr_count($output, '# TYPE '))->toBe(10_000)
        ->and($elapsed)->toBeLessThan(2.0);
});
