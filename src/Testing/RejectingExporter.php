<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Testing;

use Cbox\Telemetry\Contracts\Exporter;
use Cbox\Telemetry\Support\ExportResult;
use Cbox\Telemetry\Support\SignalSet;
use Cbox\Telemetry\Support\TelemetryBatch;

/**
 * An exporter that refuses everything, for testing what an application
 * does when the backend says no.
 *
 * `CollectingExporter` always answers ok(), so a suite built only on it
 * can never prove that a rejection is noticed — which is exactly the
 * failure mode `telemetry:flush` shipped with. Register this next to it
 * to assert the unhappy path: a non-zero exit from the scheduled flush,
 * an alert, a log line.
 *
 *     Telemetry::addExporter(new RejectingExporter);
 *     $this->artisan('telemetry:flush')->assertFailed();
 *
 * Pass any ExportResult to model a different backend answer —
 * `ExportResult::retryable('HTTP 503')`, `ExportResult::partial(3)`.
 */
final class RejectingExporter implements Exporter
{
    public readonly ExportResult $result;

    /** @var list<TelemetryBatch> */
    private array $batches = [];

    public function __construct(?ExportResult $result = null, private readonly string $name = 'rejecting')
    {
        $this->result = $result ?? ExportResult::failed('HTTP 400: {"message":"rejected by the testing exporter"}');
    }

    public function name(): string
    {
        return $this->name;
    }

    public function supports(): SignalSet
    {
        return SignalSet::all();
    }

    public function export(TelemetryBatch $batch): ExportResult
    {
        $this->batches[] = $batch;

        return $this->result;
    }

    /**
     * The batches it was offered — a rejected export still happened.
     *
     * @return list<TelemetryBatch>
     */
    public function batches(): array
    {
        return $this->batches;
    }
}
