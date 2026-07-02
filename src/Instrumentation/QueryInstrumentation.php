<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\SpanKind;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;

/**
 * Records a backdated client span for every query executed inside an
 * active span. Queries outside a trace (tinker, isolated commands) are
 * ignored — no orphan root spans.
 */
final class QueryInstrumentation
{
    private const MAX_QUERY_LENGTH = 500;

    private float $minDurationMs = 0.0;

    public function __construct(private readonly TelemetryManager $telemetry) {}

    public function register(Dispatcher $events, float $minDurationMs = 0.0): void
    {
        $this->minDurationMs = $minDurationMs;

        $events->listen(QueryExecuted::class, $this->queryExecuted(...));
    }

    private function queryExecuted(QueryExecuted $event): void
    {
        $current = $this->telemetry->currentSpan();

        // Only record inside a *sampled* trace — unsampled traces must not
        // pay per-query span cost on N+1-heavy requests.
        if ($current === null || ! $current->sampled) {
            return;
        }

        // Optional noise floor: skip sub-threshold queries entirely.
        if ($event->time < $this->minDurationMs) {
            return;
        }

        FailSafe::guard(function () use ($event) {
            $this->telemetry->tracer()->recordSpan(
                'db.query',
                $event->time,
                [
                    'db.system.name' => $event->connection->getDriverName(),
                    'db.namespace' => $event->connectionName,
                    'db.query.text' => mb_substr($event->sql, 0, self::MAX_QUERY_LENGTH),
                ],
                SpanKind::Client,
            );
        });
    }
}
