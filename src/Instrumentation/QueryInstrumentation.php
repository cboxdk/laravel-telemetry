<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\SpanKind;
use Illuminate\Contracts\Container\Container;
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

    private bool $detectDuplicates = true;

    private int $duplicateThreshold = 3;

    /** @var array<string, int> occurrence count per query fingerprint, scoped to the current trace id */
    private array $queryCounts = [];

    private ?string $currentTraceId = null;

    public function __construct(private readonly Container $container) {}

    public function register(Dispatcher $events, float $minDurationMs = 0.0, bool $detectDuplicates = true, int $duplicateThreshold = 3): void
    {
        $this->minDurationMs = $minDurationMs;
        $this->detectDuplicates = $detectDuplicates;
        $this->duplicateThreshold = max(2, $duplicateThreshold);

        $events->listen(QueryExecuted::class, $this->queryExecuted(...));
    }

    private function queryExecuted(QueryExecuted $event): void
    {
        // Resolved per event so Telemetry::fake() swaps take effect.
        $telemetry = $this->container->make(TelemetryManager::class);

        $current = $telemetry->currentSpan();

        if ($current === null) {
            return;
        }

        // Tallies are cheap and land on the root span ("12 queries /
        // 48 ms"), regardless of span-level noise floors.
        $telemetry->tracer()->bumpStat('db.query.count', 1);
        $telemetry->tracer()->bumpStat('db.query.time_ms', (float) $event->time);

        if ($this->detectDuplicates) {
            $this->detectDuplicate($telemetry, $event);
        }

        // Only record spans inside a *sampled* trace — unsampled traces
        // must not pay per-query span cost on N+1-heavy requests.
        if (! $current->sampled) {
            return;
        }

        // Optional noise floor: skip sub-threshold queries entirely.
        if ($event->time < $this->minDurationMs) {
            return;
        }

        FailSafe::guard(function () use ($event, $telemetry) {
            $telemetry->tracer()->recordSpan(
                'db.query',
                $event->time,
                [
                    'db.system.name' => $event->connection->getDriverName(),
                    'db.namespace' => $event->connectionName,
                    'db.query.text' => mb_substr($event->sql, 0, self::MAX_QUERY_LENGTH),
                ],
                SpanKind::Client,
                detail: true,
            );
        });
    }

    /**
     * The actual N+1 smell: this exact query (already parameterized by
     * Laravel — `?`/named bindings, not interpolated literals — so the
     * raw SQL text is already a solid fingerprint without normalization)
     * ran again in the same trace. Scoped by trace id, not request/job
     * boundary — cheaper than a dedicated per-instrumentation reset hook,
     * and correct for the common case (a queue worker's next job inherits
     * a different, freshly-dispatched trace id).
     *
     * Fires exactly once per distinct query, at the threshold crossing —
     * a smell detector, not a per-occurrence spam source.
     */
    private function detectDuplicate(TelemetryManager $telemetry, QueryExecuted $event): void
    {
        $traceId = $telemetry->traceId();

        if ($traceId !== $this->currentTraceId) {
            $this->currentTraceId = $traceId;
            $this->queryCounts = [];
        }

        $fingerprint = hash('xxh3', $event->connectionName.'|'.$event->sql);
        $count = ($this->queryCounts[$fingerprint] ?? 0) + 1;
        $this->queryCounts[$fingerprint] = $count;

        if ($count !== $this->duplicateThreshold) {
            return;
        }

        FailSafe::guard(function () use ($telemetry, $event, $count) {
            $telemetry->tracer()->bumpStat('db.query.duplicate.count', 1);

            $telemetry->counter('db.queries.duplicated', 'Distinct queries that repeated identically within one trace (N+1 smell)')
                ->inc(1, ['connection' => $event->connectionName]);

            $telemetry->event('db.query.duplicate_detected', [
                'db.system.name' => $event->connection->getDriverName(),
                'db.namespace' => $event->connectionName,
                'db.query.text' => mb_substr($event->sql, 0, self::MAX_QUERY_LENGTH),
                'db.query.repeat_count' => $count,
            ]);
        });
    }
}
