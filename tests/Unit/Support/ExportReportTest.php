<?php

declare(strict_types=1);

use Cbox\Telemetry\Support\ExportOutcome;
use Cbox\Telemetry\Support\ExportReport;
use Cbox\Telemetry\Support\ExportResult;
use Cbox\Telemetry\Support\ExportStatus;

it('is successful when there is nothing to report', function () {
    $report = new ExportReport;

    expect($report->successful())->toBeTrue()
        ->and($report->attempted())->toBe(0)
        ->and($report->items)->toBe(0);
});

it('does not count a skipped exporter as an attempt or a failure', function () {
    $report = (new ExportReport(items: 3))->with(ExportOutcome::skipped('prometheus'));

    expect($report->attempted())->toBe(0)
        ->and($report->failures())->toBe([])
        ->and($report->successful())->toBeTrue();
});

it('counts partial acceptance as a problem worth reporting, not a plain success', function () {
    $report = (new ExportReport(items: 12))
        ->with(ExportOutcome::of('otlp', ExportResult::partial(4, 'out-of-order sample')));

    expect($report->accepted())->toBe(1)
        ->and($report->failures())->toBe([])
        ->and($report->rejected())->toBe(4)
        ->and($report->rejections())->toHaveCount(1)
        ->and($report->successful())->toBeFalse();
});

it('summarises a mixed batch honestly', function () {
    $report = (new ExportReport(items: 57))
        ->with(ExportOutcome::of('collecting', ExportResult::ok()))
        ->with(ExportOutcome::of('otlp', ExportResult::failed('HTTP 400: {"message":"bad"}')))
        ->with(ExportOutcome::of('backup', ExportResult::ok()))
        ->with(ExportOutcome::skipped('prometheus'));

    expect($report->summary())->toBe('2 of 3 exporters accepted the batch')
        ->and($report->successful())->toBeFalse()
        ->and($report->failures())->toHaveCount(1)
        ->and($report->failures()[0]->exporter)->toBe('otlp')
        ->and($report->failures()[0]->status)->toBe(ExportStatus::Failed)
        ->and($report->failures()[0]->describe())->toBe('rejected: HTTP 400: {"message":"bad"}');
});

it('keeps a retryable failure distinct from a permanent one', function () {
    $outcome = ExportOutcome::of('otlp', ExportResult::retryable('HTTP 503: ingester unavailable', 30));

    expect($outcome->status)->toBe(ExportStatus::Retryable)
        ->and($outcome->accepted())->toBeFalse()
        ->and($outcome->describe())->toContain('HTTP 503: ingester unavailable');
});

it('names an exporter that threw instead of pretending it succeeded', function () {
    $outcome = ExportOutcome::threw('custom');

    expect($outcome->status)->toBe(ExportStatus::Error)
        ->and($outcome->accepted())->toBeFalse()
        ->and($outcome->describe())->toContain('the exporter threw');
});

it('merges the reports of two flushes', function () {
    $metrics = (new ExportReport(items: 5))->with(ExportOutcome::of('otlp', ExportResult::ok()));
    $spans = (new ExportReport(items: 2))->with(ExportOutcome::of('otlp', ExportResult::failed('HTTP 400')));

    $merged = $metrics->merge($spans);

    expect($merged->items)->toBe(7)
        ->and($merged->attempted())->toBe(2)
        ->and($merged->accepted())->toBe(1)
        ->and($merged->successful())->toBeFalse();
});
