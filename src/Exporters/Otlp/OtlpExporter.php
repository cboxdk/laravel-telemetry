<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Exporters\Otlp;

use Cbox\Telemetry\Contracts\Exporter;
use Cbox\Telemetry\Support\ExportResult;
use Cbox\Telemetry\Support\SharedState;
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
     * until a deadline held in SharedState, so a dead collector costs
     * the pool one timeout per cooldown window rather than one timeout
     * per request. A plain static would be reset by PHP-FPM between
     * requests, which is precisely the case the breaker exists for.
     *
     * One key, not one per endpoint: the state is shared by the workers
     * of a pool, and a pool runs one application with one configured
     * endpoint.
     */
    private const CIRCUIT_KEY = 'otlp:circuit';

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
        return time() < SharedState::deadline(self::CIRCUIT_KEY);
    }

    public function export(TelemetryBatch $batch): ExportResult
    {
        if (self::circuitOpen()) {
            return ExportResult::retryable('circuit open — recent transport failure');
        }

        $results = [];

        foreach ($this->requests($batch) as [$path, $payload]) {
            $result = $this->transport->post($path, $payload);
            $results[] = $result;

            // Stop only when the collector could not be REACHED. A
            // batch carries up to three signals to one endpoint, and
            // paying its connect timeout once per signal is what this
            // avoids. A 429 or a 503 is not that: it came back from a
            // server that answered, the next signal may well be
            // accepted, and skipping it drops data the manager has
            // already handed over and cleared.
            if ($result->unreachable) {
                break;
            }
        }

        return $this->combine($results);
    }

    /**
     * The signals in a batch, in the order they are posted.
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function requests(TelemetryBatch $batch): array
    {
        $requests = [];

        if ($batch->spans !== []) {
            $requests[] = ['/v1/traces', $this->serializer->traces($batch->spans)];
        }

        if ($batch->metrics !== []) {
            $requests[] = ['/v1/metrics', $this->serializer->metrics($batch->metrics)];
        }

        if ($batch->events !== []) {
            $requests[] = ['/v1/logs', $this->serializer->logs($batch->events)];
        }

        return $requests;
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
                    SharedState::remember(
                        self::CIRCUIT_KEY,
                        time() + ($result->retryAfterSeconds ?? self::DEFAULT_COOLDOWN_SECONDS),
                    );
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
        SharedState::forget(self::CIRCUIT_KEY);
    }
}
