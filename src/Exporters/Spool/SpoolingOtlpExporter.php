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
 * request pays two Redis commands — one RPUSH for every signal it
 * carries, and the LTRIM that caps the list — rather than an HTTP
 * round-trip to the collector. The
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
        $entries = [];

        if ($batch->spans !== []) {
            $entries[] = ['signal' => 'traces', 'payload' => $this->serializer->traces($batch->spans)];
        }

        if ($batch->events !== []) {
            $entries[] = ['signal' => 'logs', 'payload' => $this->serializer->logs($batch->events)];
        }

        // One call, so a request carrying both signals pays one round
        // trip rather than two — this runs on the response's clock.
        if ($entries !== []) {
            $this->spool->pushMany($entries);
        }

        if ($batch->metrics !== []) {
            return $this->inner->export(new TelemetryBatch(resource: $batch->resource, metrics: $batch->metrics));
        }

        return ExportResult::ok();
    }
}
