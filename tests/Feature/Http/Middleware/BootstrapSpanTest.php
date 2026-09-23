<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Support\FrameworkBoot;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanKind;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);

    Route::get('/boot', fn () => 'ok');
    Route::get('/health', fn () => 'ok');

    // The boot this process paid for, 50 ms before the first request.
    FrameworkBoot::flush(startedAt: microtime(true) - 0.05);
});

afterEach(fn () => FrameworkBoot::flush());

/** @return list<Span> */
function bootstrapTestSpans(CollectingExporter $collector): array
{
    Telemetry::flush();

    $spans = [];

    foreach ($collector->batches() as $batch) {
        foreach ($batch->spans as $span) {
            $spans[] = $span;
        }
    }

    return $spans;
}

it('records the boot on the first request the process serves', function () {
    $this->get('/boot')->assertOk();

    $spans = bootstrapTestSpans($this->collector);
    $bootstrap = collect($spans)->firstWhere('name', 'laravel.bootstrap');
    $server = collect($spans)->firstWhere('kind', SpanKind::Server);

    expect($bootstrap)->not->toBeNull()
        ->and($bootstrap->durationMs())->toBeGreaterThanOrEqual(50.0)
        ->and($server->attributes())->toHaveKey('laravel.bootstrap_ms');
});

it('records no boot on later requests from the same process', function () {
    // A long-lived runtime serves these from the app it booted once.
    $this->get('/boot')->assertOk();
    $this->get('/boot')->assertOk();
    $this->get('/boot')->assertOk();

    $spans = collect(bootstrapTestSpans($this->collector));

    expect($spans->where('name', 'laravel.bootstrap'))->toHaveCount(1)
        ->and($spans->where('kind', SpanKind::Server)->filter(
            fn (Span $span) => array_key_exists('laravel.bootstrap_ms', $span->attributes()),
        ))->toHaveCount(1);
});

it('lets an ignored first request consume the boot', function () {
    // Otherwise the next request would report everything since the boot,
    // idle time included, as its own bootstrap.
    config()->set('telemetry.instrument.http_ignore_paths', ['health']);

    $this->get('/health')->assertOk();
    $this->get('/boot')->assertOk();

    expect(collect(bootstrapTestSpans($this->collector))->firstWhere('name', 'laravel.bootstrap'))->toBeNull();
});
