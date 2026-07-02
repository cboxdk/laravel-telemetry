<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Exporters;

use Cbox\Telemetry\Contracts\Exporter;
use Cbox\Telemetry\Support\ExportResult;
use Cbox\Telemetry\Support\SignalSet;
use Cbox\Telemetry\Support\TelemetryBatch;

/**
 * Accepts everything, ships nothing.
 */
final class NullExporter implements Exporter
{
    public function name(): string
    {
        return 'null';
    }

    public function supports(): SignalSet
    {
        return SignalSet::all();
    }

    public function export(TelemetryBatch $batch): ExportResult
    {
        return ExportResult::ok();
    }
}
