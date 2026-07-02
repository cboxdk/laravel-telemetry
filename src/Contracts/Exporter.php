<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Contracts;

use Cbox\Telemetry\Support\ExportResult;
use Cbox\Telemetry\Support\SignalSet;
use Cbox\Telemetry\Support\TelemetryBatch;

/**
 * Ships telemetry batches to a backend.
 *
 * An exporter only receives the signals it declares support for; the
 * pipeline filters batches accordingly. Retry policy is decided by the
 * pipeline based on the ExportResult — exporters never retry themselves.
 */
interface Exporter
{
    /**
     * Unique exporter name used in configuration, e.g. "otlp".
     */
    public function name(): string;

    /**
     * The signals this exporter can handle.
     */
    public function supports(): SignalSet;

    /**
     * Export a batch. Must never throw — report failure via the result.
     */
    public function export(TelemetryBatch $batch): ExportResult;
}
