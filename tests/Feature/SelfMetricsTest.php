<?php

declare(strict_types=1);

use Cbox\Telemetry\Exporters\Otlp\OtlpExporter;
use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Metrics\Registry;
use Cbox\Telemetry\Metrics\Stores\ArrayMetricStore;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\Tracer;
use Illuminate\Support\Collection;

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);
});

function selfFamilies(): Collection
{
    return collect(Telemetry::collect())->keyBy(fn ($f) => $f->name());
}

it('records export duration and outcome when the export path runs', function () {
    Telemetry::span('work', fn () => true);
    Telemetry::event('thing.happened', ['ok' => true]);
    Telemetry::flush();

    $fams = selfFamilies();

    expect($fams)->toHaveKey('telemetry.export.duration')
        ->and($fams)->toHaveKey('telemetry.export.count');

    $count = $fams['telemetry.export.count']->samples[0];

    expect($count->labels['outcome'])->toBe('ok')
        ->and($count->labels['signal'])->toBe('traces_logs')
        ->and($count->labels)->toHaveKey('exporter');
});

it('registers the circuit-breaker gauge only when OTLP is a configured exporter', function () {
    // The default test app has no OTLP exporter, so the gauge is absent
    // (an unused breaker gauge would be pure scrape noise).
    expect(collect(Telemetry::collect())->map(fn ($f) => $f->name()))
        ->not->toContain('telemetry.export.circuit_open')
        ->and(OtlpExporter::circuitOpen())->toBeFalse();
});

it('does not emit self-metrics when disabled', function () {
    $manager = new TelemetryManager(
        enabled: true,
        registry: new Registry(new ArrayMetricStore, []),
        tracer: new Tracer(sampleRate: 1.0, maxBuffer: 5000, alwaysSampleErrors: true),
        selfMetrics: false,
    );
    $manager->addExporter(new CollectingExporter);

    $manager->span('work', fn () => true);
    $manager->flush();

    expect(collect($manager->collect())->map(fn ($f) => $f->name()))
        ->not->toContain('telemetry.export.count');
});
