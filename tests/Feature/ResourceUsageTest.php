<?php

declare(strict_types=1);

use Cbox\SystemMetrics\ProcessMetrics;
use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\SpanKind;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);
});

function serverSpan(CollectingExporter $collector)
{
    Telemetry::flush();

    foreach ($collector->batches() as $batch) {
        foreach ($batch->spans as $span) {
            if ($span->kind === SpanKind::Server) {
                return $span;
            }
        }
    }

    return null;
}

it('records peak memory and cpu time on the request span and as histograms', function () {
    Route::get('/heavy', function () {
        $waste = str_repeat('x', 2_000_000); // force some memory use

        return strlen($waste);
    });

    $this->get('/heavy')->assertOk();

    $span = serverSpan($this->collector);

    expect($span->attributes()['php.memory.peak_bytes'])->toBeGreaterThan(1_000_000)
        ->and($span->attributes())->toHaveKey('php.cpu.time_ms');

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());

    expect($families)->toHaveKeys(['http.server.memory.peak', 'http.server.cpu.time'])
        ->and($families['http.server.memory.peak']->samples[0]->labels['http.route'])->toBe('/heavy')
        ->and($families['http.server.memory.peak']->samples[0]->count)->toBe(1);
});

it('skips resource capture when disabled', function () {
    config()->set('telemetry.instrument.resources', false);

    Route::get('/light', fn () => 'ok');

    $this->get('/light');

    $span = serverSpan($this->collector);

    expect($span->attributes())->not->toHaveKey('php.memory.peak_bytes');

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());

    expect($families)->not->toHaveKey('http.server.memory.peak');
});

it('records job resource usage on worker jobs', function () {
    app('queue');

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\Jobs\HeavyJob');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('payload')->andReturn([]);

    $events = app('events');
    $events->dispatch(new JobProcessing('redis', $job));

    $waste = str_repeat('y', 1_500_000);

    $events->dispatch(new JobProcessed('redis', $job));

    Telemetry::flush();

    $consumer = collect($this->collector->batches())
        ->flatMap(fn ($batch) => $batch->spans)
        ->firstWhere(fn ($span) => $span->kind === SpanKind::Consumer);

    expect($consumer->attributes()['php.memory.peak_bytes'])->toBeGreaterThan(1_000_000)
        ->and($consumer->attributes())->toHaveKey('php.cpu.time_ms');

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());

    expect($families)->toHaveKeys(['queue.job.memory.peak', 'queue.job.cpu.time'])
        ->and($families['queue.job.memory.peak']->samples[0]->labels['job_name'] ?? $families['queue.job.memory.peak']->samples[0]->labels['job.name'])->toBe('App\Jobs\HeavyJob');
});

it('captures real process RSS and cpu utilization via cboxdk/system-metrics', function () {
    $pid = getmypid();

    if (! ProcessMetrics::snapshot($pid)->isSuccess()) {
        $this->markTestSkipped('ProcessMetrics has no source for this platform.');
    }

    Route::get('/rss', fn () => str_repeat('z', 500_000));

    $this->get('/rss')->assertOk();

    $span = serverSpan($this->collector);

    expect($span->attributes()['process.memory.rss_peak_bytes'])->toBeGreaterThan(1_000_000)
        ->and($span->attributes())->toHaveKey('process.cpu.utilization');
});
