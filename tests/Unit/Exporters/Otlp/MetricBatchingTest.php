<?php

declare(strict_types=1);

use Cbox\Telemetry\Exporters\Otlp\OtlpSerializer;
use Cbox\Telemetry\Exporters\Spool\ArraySpool;
use Cbox\Telemetry\Exporters\Spool\SpoolShipper;
use Cbox\Telemetry\Metrics\HistogramSample;
use Cbox\Telemetry\Metrics\MetricDefinition;
use Cbox\Telemetry\Metrics\MetricFamily;
use Cbox\Telemetry\Metrics\MetricType;
use Cbox\Telemetry\Metrics\Sample;
use Cbox\Telemetry\Support\ExportResult;

it('preserves every counter and complete histogram across bounded requests', function () {
    $counter = new MetricFamily(new MetricDefinition('requests', MetricType::Counter),
        array_map(fn ($i) => new Sample(['route' => (string) $i], $i), range(1, 513)),
        startUnixNano: 1793990000000000000, timeUnixNano: 1793990100000000000);
    $histogram = new MetricFamily(new MetricDefinition('duration', MetricType::Histogram),
        array_map(fn ($i) => new HistogramSample(['route' => (string) $i], [1.0, 2.0], [1, 2, 1], sum: 8.0, count: 4), range(1, 258)),
        startUnixNano: 1793990000000000000, timeUnixNano: 1793990100000000000);
    $serializer = new OtlpSerializer(['service.name' => 'batch-test']);
    $expected = $serializer->metrics([$counter, $histogram]);
    $batches = $serializer->metricBatches([$counter, $histogram]);
    $actual = [];
    foreach ($batches as $batch) {
        $count = 0;
        expect($batch['resourceMetrics'][0]['resource'])->toBe($expected['resourceMetrics'][0]['resource']);
        foreach ($batch['resourceMetrics'][0]['scopeMetrics'][0]['metrics'] as $metric) {
            $kind = isset($metric['sum']) ? 'sum' : 'histogram';
            $points = $metric[$kind]['dataPoints'];
            $count += count($points);
            if (! isset($actual[$metric['name']])) {
                $actual[$metric['name']] = $metric;
            } else {
                $actual[$metric['name']][$kind]['dataPoints'] = [...$actual[$metric['name']][$kind]['dataPoints'], ...$points];
            }
        }
        expect($count)->toBeLessThanOrEqual(256)->toBeGreaterThan(0);
    }
    expect($batches)->toHaveCount(4)
        ->and(array_values($actual))->toBe($expected['resourceMetrics'][0]['scopeMetrics'][0]['metrics']);
});

it('bounds histogram expansion before the point count limit', function () {
    $samples = array_fill(0, 40, new HistogramSample(['route' => str_repeat('r', 4000)], range(1, 100), array_fill(0, 101, 1), sum: 5050.0, count: 101));
    $serializer = new OtlpSerializer(['service.name' => 'batch-test', 'resource.context' => str_repeat('r', 16000)]);
    $batches = $serializer->metricBatches([new MetricFamily(new MetricDefinition('duration', MetricType::Histogram), $samples)]);
    expect(count($batches))->toBeGreaterThan(1);
    $count = 0;
    foreach ($batches as $batch) {
        $points = $batch['resourceMetrics'][0]['scopeMetrics'][0]['metrics'][0]['histogram']['dataPoints'];
        $count += count($points);
        expect(count($points))->toBeLessThan(40);
    }
    expect($count)->toBe(40);
});

it('retries only unaccepted metric requests in their original order', function () {
    $spool = new ArraySpool;
    foreach ([1, 2, 3] as $i) {
        $spool->push(['signal' => 'metrics', 'payload' => ['resourceMetrics' => [['sample' => $i]]]]);
    }
    $posts = [];
    $fail = true;
    $shipper = new SpoolShipper($spool, function ($path, $payload) use (&$posts, &$fail) {
        $id = $payload['resourceMetrics'][0]['sample'];
        $posts[] = $id;
        if ($id === 2 && $fail) {
            $fail = false;

            return ExportResult::retryable('unavailable');
        }

        return ExportResult::ok();
    });
    $first = $shipper->ship();
    expect($first->shipped)->toBe(1)->and($first->requeued)->toBe(2)->and($posts)->toBe([1, 2]);
    expect($shipper->ship()->shipped)->toBe(2)->and($posts)->toBe([1, 2, 2, 3])->and($spool->size())->toBe(0);
});
