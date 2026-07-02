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

    public function __construct(private readonly TelemetryManager $telemetry) {}

    public function register(Dispatcher $events): void
    {
        $events->listen(QueryExecuted::class, $this->queryExecuted(...));
    }

    private function queryExecuted(QueryExecuted $event): void
    {
        if ($this->telemetry->currentSpan() === null) {
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
