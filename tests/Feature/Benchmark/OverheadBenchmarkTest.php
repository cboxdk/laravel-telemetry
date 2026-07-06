<?php

declare(strict_types=1);
use Cbox\Telemetry\Exporters\NullExporter;
use Cbox\Telemetry\Facades\Telemetry;
use Illuminate\Support\Facades\Route;

/**
 * Not part of `composer test` — run on demand with:
 *
 *     vendor/bin/pest --group=benchmark
 *
 * Measures the package's own in-process overhead: the cost of the
 * TraceRequest middleware, default instrumentation, span/event
 * buffering and an array-store metric write, all with a null exporter
 * (no network, no Redis) — isolating "does this package's code cost
 * anything" from "how fast is my collector", which is a separate,
 * already-bounded cost (traces.otlp.timeout/connect_timeout). See
 * docs/production/performance.md for the last recorded run and
 * methodology notes (single machine, not a substitute for profiling
 * your own stack).
 *
 * A tight in-process loop, not real HTTP — measures the SAME cost a
 * real request pays (kernel handle + middleware + termination) without
 * TCP/socket noise, so the numbers reflect this package specifically.
 */
uses()->group('benchmark');

/**
 * @return array{min: float, median: float, p95: float, max: float, mean: float}
 */
function benchmarkStats(array $samplesMs): array
{
    sort($samplesMs);
    $n = count($samplesMs);

    return [
        'min' => $samplesMs[0],
        'median' => $samplesMs[intdiv($n, 2)],
        'p95' => $samplesMs[(int) floor($n * 0.95)] ?? $samplesMs[$n - 1],
        'max' => $samplesMs[$n - 1],
        'mean' => array_sum($samplesMs) / $n,
    ];
}

/**
 * @return array{min: float, median: float, p95: float, max: float, mean: float}
 */
function benchmarkRun(int $iterations = 300, int $warmup = 30): array
{
    Route::get('/__benchmark', fn () => ['ok' => true]);

    for ($i = 0; $i < $warmup; $i++) {
        test()->get('/__benchmark');
    }

    $samples = [];

    for ($i = 0; $i < $iterations; $i++) {
        $start = hrtime(true);
        test()->get('/__benchmark')->assertOk();
        $samples[] = (hrtime(true) - $start) / 1_000_000;
    }

    return benchmarkStats($samples);
}

function benchmarkReport(string $label, array $stats): void
{
    fwrite(STDERR, sprintf(
        "\n[benchmark] %-28s min=%.3fms median=%.3fms p95=%.3fms max=%.3fms mean=%.3fms\n",
        $label,
        $stats['min'],
        $stats['median'],
        $stats['p95'],
        $stats['max'],
        $stats['mean'],
    ));
}

it('measures overhead with telemetry disabled (the zero-cost baseline)', function () {
    config()->set('telemetry.enabled', false);
    $this->refreshApplication();

    $stats = benchmarkRun();
    benchmarkReport('disabled', $stats);

    expect($stats['median'])->toBeFloat();
})->skip(fn () => ! method_exists(app(), 'flush'), 'refresh unsupported');

it('measures overhead with default instrumentation, array store, no exporters', function () {
    $stats = benchmarkRun();
    benchmarkReport('enabled (array store, no exporter)', $stats);

    expect($stats['median'])->toBeFloat();
});

it('measures overhead with default instrumentation and a null exporter registered', function () {
    Telemetry::addExporter(new NullExporter);

    $stats = benchmarkRun();
    benchmarkReport('enabled (array store, null exporter)', $stats);

    expect($stats['median'])->toBeFloat();
});

it('measures overhead with tail sampling and detail-span trimming', function () {
    config()->set('telemetry.traces.details.mode', 'tail');
    $this->refreshApplication();

    Telemetry::addExporter(new NullExporter);

    $stats = benchmarkRun();
    benchmarkReport('enabled (tail mode, null exporter)', $stats);

    expect($stats['median'])->toBeFloat();
})->skip(fn () => ! method_exists(app(), 'flush'), 'refresh unsupported');
