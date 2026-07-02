<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Exporters\Spool;

use Cbox\Telemetry\Contracts\Exporter;
use Cbox\Telemetry\Exporters\Otlp\OtlpExporter;
use Cbox\Telemetry\Exporters\Otlp\OtlpSerializer;
use Cbox\Telemetry\Support\ExportResult;
use Cbox\Telemetry\Support\SignalSet;
use Cbox\Telemetry\Support\TelemetryBatch;

/**
 * OTLP with a local buffer: spans and events are serialized at
 * terminate but pushed to the spool instead of the network — the
 * request pays one Redis RPUSH, not an HTTP round-trip. The
 * `telemetry:flush` command (cron or --daemon) ships them in merged
 * batches.
 *
 * Metrics pass straight through to the wrapped exporter: their state
 * already lives in the shared store, and flushMetrics() only ever runs
 * from the flush command's own process.
 */
final class SpoolingOtlpExporter implements Exporter
{
    public function __construct(
        private readonly OtlpExporter $inner,
        private readonly OtlpSerializer $serializer,
        private readonly Spool $spool,
    ) {}

    public function name(): string
    {
        return 'otlp';
    }

    public function supports(): SignalSet
    {
        return $this->inner->supports();
    }

    public function export(TelemetryBatch $batch): ExportResult
    {
        if ($batch->spans !== []) {
            $this->spool->push(['signal' => 'traces', 'payload' => $this->serializer->traces($batch->spans)]);
        }

        if ($batch->events !== []) {
            $this->spool->push(['signal' => 'logs', 'payload' => $this->serializer->logs($batch->events)]);
        }

        if ($batch->metrics !== []) {
            return $this->inner->export(new TelemetryBatch(resource: $batch->resource, metrics: $batch->metrics));
        }

        return ExportResult::ok();
    }
}
