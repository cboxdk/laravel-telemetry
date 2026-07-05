<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\SpanKind;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class TelemetryTestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void {}
}

it('runs jobs in a consumer span that is a child of the dispatch site', function () {
    config()->set('queue.default', 'sync');

    $collector = new CollectingExporter;
    Telemetry::addExporter($collector);

    $dispatchSpan = Telemetry::span('dispatching');

    TelemetryTestJob::dispatch();

    $dispatchSpan->end();

    Telemetry::flush();

    $jobSpans = [];

    foreach ($collector->batches() as $batch) {
        foreach ($batch->spans as $span) {
            if ($span->kind === SpanKind::Consumer) {
                $jobSpans[] = $span;
            }
        }
    }

    expect($jobSpans)->toHaveCount(1)
        ->and($jobSpans[0]->name)->toContain('TelemetryTestJob')
        ->and($jobSpans[0]->attributes()['messaging.system'])->toBe('laravel_queue')
        // Full W3C propagation: same trace, parented to the dispatch span.
        ->and($jobSpans[0]->traceId)->toBe($dispatchSpan->traceId)
        ->and($jobSpans[0]->parentSpanId)->toBe($dispatchSpan->spanId);
});

it('counts processed jobs', function () {
    config()->set('queue.default', 'sync');

    TelemetryTestJob::dispatch();

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());

    expect($families)->toHaveKey('queue.jobs.processed')
        ->and($families['queue.jobs.processed']->samples[0]->value)->toBe(1.0);
});
