<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Exporters\Otlp;

use Cbox\Telemetry\Contracts\Exporter;
use Cbox\Telemetry\Support\ExportResult;
use Cbox\Telemetry\Support\SignalSet;
use Cbox\Telemetry\Support\TelemetryBatch;

/**
 * Direct OTLP/HTTP JSON export — no collector, no SDK, no protobuf.
 *
 * Spans and events are posted at terminate; metrics are posted by the
 * scheduled `telemetry:flush` command with cumulative temporality read
 * from the shared store (which is what makes metrics correct under
 * shared-nothing FPM).
 */
final class OtlpExporter implements Exporter
{
    /**
     * Circuit breaker: after a retryable failure, exports are skipped
     * until this timestamp. Static so it survives across requests within
     * a long-lived FPM/Octane worker — a dead collector costs each worker
     * one timeout, not one per request.
     */
    private static int $openUntil = 0;

    private const DEFAULT_COOLDOWN_SECONDS = 30;

    public function __construct(
        private readonly OtlpTransport $transport,
        private readonly OtlpSerializer $serializer,
    ) {}

    public function name(): string
    {
        return 'otlp';
    }

    public function supports(): SignalSet
    {
        return SignalSet::all();
    }

    /**
     * Whether the per-process circuit breaker is currently open — surfaced
     * as the telemetry.export.circuit_open self-metric.
     */
    public static function circuitOpen(): bool
    {
        return time() < self::$openUntil;
    }

    public function export(TelemetryBatch $batch): ExportResult
    {
        if (time() < self::$openUntil) {
            return ExportResult::retryable('circuit open — recent transport failure');
        }

        $results = [];

        if ($batch->spans !== []) {
            $results[] = $this->transport->post('/v1/traces', $this->serializer->traces($batch->spans));
        }

        if ($batch->metrics !== []) {
            $results[] = $this->transport->post('/v1/metrics', $this->serializer->metrics($batch->metrics));
        }

        if ($batch->events !== []) {
            $results[] = $this->transport->post('/v1/logs', $this->serializer->logs($batch->events));
        }

        return $this->combine($results);
    }

    /**
     * @param  list<ExportResult>  $results
     */
    private function combine(array $results): ExportResult
    {
        if ($results === []) {
            return ExportResult::ok();
        }

        foreach ($results as $result) {
            if (! $result->success) {
                if ($result->retryable) {
                    self::$openUntil = time() + ($result->retryAfterSeconds ?? self::DEFAULT_COOLDOWN_SECONDS);
                }

                return $result;
            }
        }

        foreach ($results as $result) {
            if ($result->rejected > 0) {
                return $result;
            }
        }

        return ExportResult::ok();
    }

    /**
     * @internal test hook
     */
    public static function resetCircuit(): void
    {
        self::$openUntil = 0;
    }
}
