<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Testing;

use Cbox\Telemetry\Contracts\Exporter;
use Cbox\Telemetry\Support\ExportResult;
use Cbox\Telemetry\Support\SignalSet;
use Cbox\Telemetry\Support\TelemetryBatch;

/**
 * Keeps every exported batch in memory — backs Telemetry::fake().
 */
final class CollectingExporter implements Exporter
{
    /** @var list<TelemetryBatch> */
    private array $batches = [];

    public function name(): string
    {
        return 'collecting';
    }

    public function supports(): SignalSet
    {
        return SignalSet::all();
    }

    public function export(TelemetryBatch $batch): ExportResult
    {
        $this->batches[] = $batch;

        return ExportResult::ok();
    }

    /**
     * @return list<TelemetryBatch>
     */
    public function batches(): array
    {
        return $this->batches;
    }
}
