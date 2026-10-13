<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Exporters\Spool;

use Cbox\Telemetry\Contracts\Exporter;
use Cbox\Telemetry\Exporters\Otlp\OtlpExporter;
use Cbox\Telemetry\Exporters\Otlp\OtlpSerializer;
use Cbox\Telemetry\Support\ExportResult;
use Cbox\Telemetry\Support\SharedState;
use Cbox\Telemetry\Support\SignalSet;
use Cbox\Telemetry\Support\TelemetryBatch;
use Throwable;

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
    /**
     * Circuit breaker for the push. The OTLP exporter's breaker never
     * sees this path: a request hands its spans to the spool, not to
     * the collector, so a spool that is down or blackholed used to cost
     * every traced request the spool's full connect or read timeout,
     * after the response but on the PHP-FPM worker's clock. Under load
     * that is how telemetry exhausts a pool's workers. After a failed
     * push, requests skip the spool until the deadline, so the pool
     * pays one timeout per window instead of one per request.
     *
     * Held in SharedState like the OTLP breaker, so it is shared by a
     * PHP-FPM pool where APCu is present and per process otherwise.
     */
    private const CIRCUIT_KEY = 'spool:circuit';

    private const COOLDOWN_SECONDS = 30;

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

    /**
     * Whether pushes are currently skipped after a spool failure —
     * surfaced as the telemetry.spool.circuit_open self-metric.
     */
    public static function circuitOpen(): bool
    {
        return time() < SharedState::deadline(self::CIRCUIT_KEY);
    }

    public static function resetCircuit(): void
    {
        SharedState::forget(self::CIRCUIT_KEY);
    }

    public function export(TelemetryBatch $batch): ExportResult
    {
        $skipped = false;

        if ($batch->spans !== [] || $batch->events !== []) {
            if (self::circuitOpen()) {
                // Dropped, not held: telemetry is lossy by design, and a
                // request that buffered what it could not push would grow
                // for as long as the spool stays down.
                $skipped = true;
            } else {
                $this->push($batch);
            }
        }

        if ($batch->metrics !== []) {
            $result = $this->inner->export(new TelemetryBatch(resource: $batch->resource, metrics: $batch->metrics));

            if (! $result->success || ! $skipped) {
                return $result;
            }
        }

        return $skipped
            ? ExportResult::retryable('spool circuit open — recent spool failure')
            : ExportResult::ok();
    }

    private function push(TelemetryBatch $batch): void
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
        try {
            $this->spool->pushMany($entries);
        } catch (Throwable $e) {
            // Rethrown so the fail-safe still reports it; the breaker only
            // decides what the next requests do.
            SharedState::remember(self::CIRCUIT_KEY, time() + self::COOLDOWN_SECONDS);

            throw $e;
        }
    }
}
