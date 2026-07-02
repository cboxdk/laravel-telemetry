<?php

declare(strict_types=1);

use Cbox\Telemetry\Metrics\Registry;
use Cbox\Telemetry\Metrics\Stores\ArrayMetricStore;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\Tracer;

function tailManager(float $slowRequestMs = 1000, float $slowSpanMs = 100): array
{
    $manager = new TelemetryManager(
        enabled: true,
        registry: new Registry(new ArrayMetricStore, []),
        tracer: new Tracer,
        tailDetails: true,
        slowRequestMs: $slowRequestMs,
        slowSpanMs: $slowSpanMs,
    );

    $collector = new CollectingExporter;
    $manager->addExporter($collector);

    return [$manager, $collector];
}

function flushedSpanNames(CollectingExporter $collector): array
{
    return collect($collector->batches())->flatMap(fn ($batch) => $batch->spans)->pluck('name')->all();
}

it('drops detail spans from healthy fast traces but keeps the skeleton and tallies', function () {
    [$manager, $collector] = tailManager();

    $manager->span('GET /shop', function () use ($manager) {
        $manager->tracer()->recordSpan('cache.hit', 0.5, ['cache.key' => 'k'], SpanKind::Client, detail: true);
        $manager->tracer()->recordSpan('db.query', 2.0, [], SpanKind::Client, detail: true);
        $manager->tracer()->bumpStat('db.query.count', 1);
    });

    $manager->flush();

    $names = flushedSpanNames($collector);

    expect($names)->toContain('GET /shop')
        ->not->toContain('cache.hit')
        ->not->toContain('db.query');

    // Aggregates survive on the root span.
    $root = collect($collector->batches())->flatMap(fn ($b) => $b->spans)->firstWhere('name', 'GET /shop');

    expect($root->attributes()['db.query.count'])->toBe(1);
});

it('keeps every detail for traces with an error span', function () {
    [$manager, $collector] = tailManager();

    try {
        $manager->span('GET /broken', function () use ($manager) {
            $manager->tracer()->recordSpan('cache.hit', 0.5, ['cache.key' => 'k'], SpanKind::Client, detail: true);

            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    $manager->flush();

    expect(flushedSpanNames($collector))->toContain('GET /broken')
        ->toContain('cache.hit');
});

it('keeps every detail for slow requests', function () {
    [$manager, $collector] = tailManager(slowRequestMs: 1.0);

    $manager->span('GET /slow', function () use ($manager) {
        $manager->tracer()->recordSpan('cache.miss', 0.2, [], SpanKind::Client, detail: true);
        usleep(5_000); // request exceeds the 1 ms threshold
    });

    $manager->flush();

    expect(flushedSpanNames($collector))->toContain('cache.miss');
});

it('keeps every detail when a single query is slow', function () {
    [$manager, $collector] = tailManager(slowSpanMs: 50.0);

    $manager->span('GET /n-plus-one', function () use ($manager) {
        $manager->tracer()->recordSpan('db.query', 2.0, [], SpanKind::Client, detail: true);
        $manager->tracer()->recordSpan('db.query', 350.0, [], SpanKind::Client, detail: true); // the slow one
    });

    $manager->flush();

    $queries = collect($collector->batches())->flatMap(fn ($b) => $b->spans)
        ->where('name', 'db.query');

    // BOTH queries kept — the slow one made the whole trace interesting.
    expect($queries)->toHaveCount(2);
});

it('scopes the decision per trace in the same flush', function () {
    [$manager, $collector] = tailManager();

    // Trace A: healthy.
    $manager->span('job A', function () use ($manager) {
        $manager->tracer()->recordSpan('cache.hit', 0.1, [], SpanKind::Client, detail: true);
    });
    $manager->resetContext();

    // Trace B: failing.
    try {
        $manager->span('job B', fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }

    $manager->flush();

    $spans = collect($collector->batches())->flatMap(fn ($b) => $b->spans);
    $traceA = $spans->firstWhere('name', 'job A');

    // A's cache detail dropped; B fully kept.
    expect($spans->where('traceId', $traceA->traceId)->pluck('name')->all())->toBe(['job A'])
        ->and($spans->pluck('name'))->toContain('job B');
});

it('keeps details on buffer-cap force flushes — pathological traces are interesting', function () {
    $manager = new TelemetryManager(
        enabled: true,
        registry: new Registry(new ArrayMetricStore, []),
        tracer: $tracer = new Tracer(maxBuffer: 3),
        tailDetails: true,
    );

    $collector = new CollectingExporter;
    $manager->addExporter($collector);

    $root = $manager->span('massive.job');

    foreach (range(1, 4) as $i) {
        $tracer->recordSpan('cache.hit', 0.1, [], SpanKind::Client, detail: true); // triggers cap flush
    }

    $root->end();
    $manager->flush();

    expect(collect($collector->batches())->flatMap(fn ($b) => $b->spans)->where('name', 'cache.hit')->count())
        ->toBeGreaterThanOrEqual(3);
});
