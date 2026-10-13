<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use BackedEnum;
use Cbox\Telemetry\Support\DbSystem;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\Support\Overhead;
use Cbox\Telemetry\Support\OwnWork;
use Cbox\Telemetry\Support\SourceLocator;
use Cbox\Telemetry\Support\SqlShape;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\SpanKind;
use DateTimeInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Stringable;
use UnitEnum;

/**
 * Records a backdated client span for every query executed inside an
 * active span. Queries outside a trace (tinker, isolated commands) are
 * ignored — no orphan root spans.
 */
final class QueryInstrumentation
{
    /**
     * The query text is NOT cut here.
     *
     * It used to be, at 500 characters, and that is the same bug the
     * span-level value cap turned out to be: redaction runs at export,
     * so cutting
     * `SELECT 1 /* …https://alice:hunter2@example.test/ *​/` before the
     * `@` leaves a string the userinfo pattern no longer recognises,
     * and the password ships. A comment in a query is not an exotic
     * place for a connection string to appear — ORMs and migration
     * tools put them there.
     *
     * Length belongs to `telemetry.redaction.max_value_length`, which
     * redacts first and cuts after; the memory a long query occupies
     * until then is bounded by the tracer's byte budget.
     */

    /** Distinct query fingerprints held per trace before the maps reset. */
    private const MAX_FINGERPRINTS = 10_000;

    /** Attach code.* to no query span. */
    public const ORIGIN_OFF = 'off';

    /** Attach code.* to slow and repeated queries only — the ones worth pointing at. */
    public const ORIGIN_FLAGGED = 'flagged';

    /** Attach code.* to every recorded query span. */
    public const ORIGIN_ALL = 'all';

    public const REPEAT_DUPLICATE = 'duplicate';

    public const REPEAT_N_PLUS_ONE = 'n_plus_one';

    private float $minDurationMs = 0.0;

    private bool $detectDuplicates = true;

    private int $duplicateThreshold = 3;

    private string $origin = self::ORIGIN_FLAGGED;

    private float $slowMs = 100.0;

    private string $basePath = '';

    /** @var array<string, int> occurrence count per exact query (sql + bindings), scoped to the current trace id */
    private array $queryCounts = [];

    /**
     * Per shape: occurrences, the first exact fingerprint seen, whether a
     * different one has turned up since, and whether it was reported.
     *
     * @var array<string, array{count: int, first: string, varied: bool, reported: bool}>
     */
    private array $shapes = [];

    private ?string $currentTraceId = null;

    /**
     * Shape hash per connection + statement text. The same text comes back
     * constantly — that is what an N+1 is — and the normalizing regexes are
     * most of what shape detection costs. Process-wide, not per trace: the
     * mapping never changes. Bounded like the fingerprint maps.
     *
     * @var array<string, string>
     */
    private array $shapeCache = [];

    /** Whether the query just inspected crossed a threshold — the one occurrence that gets an origin. */
    private bool $crossed = false;

    /**
     * The crossing query's origin, located once for its event and reused
     * for its span.
     *
     * @var array<string, string|int>|null
     */
    private ?array $crossingOrigin = null;

    public function __construct(private readonly Container $container) {}

    public function register(
        Dispatcher $events,
        float $minDurationMs = 0.0,
        bool $detectDuplicates = true,
        int $duplicateThreshold = 3,
        string $origin = self::ORIGIN_FLAGGED,
        float $slowMs = 100.0,
        string $basePath = '',
    ): void {
        $this->minDurationMs = $minDurationMs;
        $this->detectDuplicates = $detectDuplicates;
        $this->duplicateThreshold = max(2, $duplicateThreshold);
        $this->origin = in_array($origin, [self::ORIGIN_OFF, self::ORIGIN_ALL], true) ? $origin : self::ORIGIN_FLAGGED;
        $this->slowMs = $slowMs;
        $this->basePath = $basePath;

        $events->listen(QueryExecuted::class, $this->queryExecuted(...));
    }

    private function queryExecuted(QueryExecuted $event): void
    {
        if (OwnWork::active()) {
            return;
        }

        Overhead::measure(fn () => FailSafe::guard(fn () => $this->record($event)));
    }

    /**
     * Everything this listener does, inside one guard.
     *
     * Resolving the manager and asking it for the current span used to
     * happen out here, ahead of the guard below — and this listener fires
     * for EVERY query in the application. A container binding that throws
     * (a store driver misconfigured, an app decorating the binding, a lazy
     * connection that cannot be made) would therefore turn every single
     * database query into an exception from the telemetry package. There
     * is no failure in this package with a larger blast radius, and it
     * was one `make()` outside a try.
     */
    private function record(QueryExecuted $event): void
    {
        // Resolved per event so Telemetry::fake() swaps take effect.
        $telemetry = $this->container->make(TelemetryManager::class);

        $current = $telemetry->currentSpan();

        if ($current === null) {
            return;
        }

        $repeat = null;
        $this->crossed = false;
        $this->crossingOrigin = null;

        // Guarded as a whole: this listener fires for EVERY query, and even
        // instrument registration (a type conflict on `db.queries`) must
        // never surface as a per-query exception in the app.
        FailSafe::guard(function () use ($event, $telemetry, &$repeat) {
            // Tallies are cheap and land on the root span ("12 queries /
            // 48 ms"), regardless of span-level noise floors.
            $telemetry->tracer()->bumpStat('db.query.count', 1);
            $telemetry->tracer()->bumpStat('db.query.time_ms', (float) $event->time);

            if ($event->time >= $this->slowMs) {
                $telemetry->tracer()->bumpStat('db.query.slow.count', 1);
            }

            // Fleet-level counter — the database twin of redis.commands, so a
            // dashboard can show DB activity per host without depending on
            // tail-sampled traces. Bounded labels (configured connections × a
            // handful of drivers); query text never becomes a label.
            $telemetry->counter('db.queries', 'Database queries executed')->inc(1, [
                'connection' => $event->connectionName,
                'driver' => $event->connection->getDriverName(),
            ]);

            if ($this->detectDuplicates) {
                $repeat = $this->detectRepeat($telemetry, $event);
            }
        });

        // Only record spans inside a *sampled* trace — unsampled traces
        // must not pay per-query span cost on N+1-heavy requests.
        if (! $current->sampled) {
            return;
        }

        // Optional noise floor: skip sub-threshold queries entirely.
        if ($event->time < $this->minDurationMs) {
            return;
        }

        FailSafe::guard(function () use ($event, $telemetry, $repeat) {
            $attributes = [
                'db.system.name' => DbSystem::name($event->connection->getDriverName()),
                'laravel.db.connection' => $event->connectionName,
                'db.query.text' => $event->sql,
                'laravel.db.query.shape' => $this->shape($event),
            ];

            if ($repeat !== null) {
                $attributes['laravel.db.query.repeat'] = $repeat;
            }

            // The crossing occurrence, not every repeat after it: a loop of a
            // thousand queries would otherwise pay a thousand backtraces to
            // learn the same line. The others share its shape.
            if ($this->wantsOrigin($this->crossed || $event->time >= $this->slowMs)) {
                $attributes += $this->crossingOrigin ?? SourceLocator::locate($this->basePath) ?? [];
            }

            $telemetry->tracer()->recordSpan(
                'db.query',
                $event->time,
                $attributes,
                SpanKind::Client,
                detail: true,
            );
        });
    }

    private function shape(QueryExecuted $event): string
    {
        $key = $event->connectionName.'|'.$event->sql;

        if (isset($this->shapeCache[$key])) {
            return $this->shapeCache[$key];
        }

        if (count($this->shapeCache) >= self::MAX_FINGERPRINTS) {
            $this->shapeCache = [];
        }

        return $this->shapeCache[$key] = SqlShape::hash($event->connectionName, $event->sql);
    }

    private function wantsOrigin(bool $flagged): bool
    {
        return $this->origin === self::ORIGIN_ALL
            || ($this->origin === self::ORIGIN_FLAGGED && $flagged);
    }

    /**
     * Two smells, told apart by the bindings.
     *
     *  - DUPLICATE: the same statement with the same values, again. The
     *    answer is already in hand — memoize it, cache it, pass it down.
     *  - N+1: the same statement SHAPE with different values, once per
     *    row of something loaded earlier. The answer is an eager load or
     *    a whereIn.
     *
     * Until 3.x the detector compared query text only, so both landed in
     * "duplicate" — and the fix for one is no fix for the other.
     *
     * Scoped by trace id, not request/job boundary — cheaper than a
     * dedicated per-instrumentation reset hook, and correct for the common
     * case (a queue worker's next job inherits a different, freshly-
     * dispatched trace id). Each smell fires once per distinct query, at
     * the threshold crossing — a smell detector, not a per-occurrence spam
     * source. The return value marks THIS occurrence for its span.
     */
    private function detectRepeat(TelemetryManager $telemetry, QueryExecuted $event): ?string
    {
        $traceId = $telemetry->traceId();

        if ($traceId !== $this->currentTraceId) {
            $this->currentTraceId = $traceId;
            $this->queryCounts = [];
            $this->shapes = [];
        }

        // Bounded. One trace can run an unbounded number of DISTINCT
        // queries — a bulk import, a migration, a long job — and these maps
        // are keyed by fingerprint, so without a cap they grow for as long
        // as the trace does.
        //
        // Clearing rather than refusing to add: detection restarts from
        // zero, which loses nothing that matters (a query repeating enough
        // to be a smell will repeat again) and keeps the memory flat
        // instead of frozen at the cap with stale counts.
        if (count($this->queryCounts) >= self::MAX_FINGERPRINTS || count($this->shapes) >= self::MAX_FINGERPRINTS) {
            $this->queryCounts = [];
            $this->shapes = [];
        }

        $exact = hash('xxh3', $event->connectionName.'|'.$event->sql.'|'.self::bindingsKey($event->bindings));
        $count = ($this->queryCounts[$exact] ?? 0) + 1;
        $this->queryCounts[$exact] = $count;

        if ($count === $this->duplicateThreshold) {
            $this->crossed = true;
            $this->reportDuplicate($telemetry, $event, $count);
        }

        $repeat = $count >= $this->duplicateThreshold ? self::REPEAT_DUPLICATE : null;

        if (! SqlShape::isRead($event->sql) || SqlShape::isPagination($event->sql)) {
            return $repeat;
        }

        $shape = $this->shape($event);
        $state = $this->shapes[$shape] ?? ['count' => 0, 'first' => $exact, 'varied' => false, 'reported' => false];
        $state['count']++;
        $state['varied'] = $state['varied'] || $exact !== $state['first'];

        if (! $state['reported'] && $state['varied'] && $state['count'] >= $this->duplicateThreshold) {
            $state['reported'] = true;
            $this->crossed = true;
            $this->reportNPlusOne($telemetry, $event, $shape, $state['count']);
        }

        $this->shapes[$shape] = $state;

        return $repeat ?? ($state['reported'] ? self::REPEAT_N_PLUS_ONE : null);
    }

    private function reportDuplicate(TelemetryManager $telemetry, QueryExecuted $event, int $count): void
    {
        FailSafe::guard(function () use ($telemetry, $event, $count) {
            $telemetry->tracer()->bumpStat('db.query.duplicate.count', 1);

            $telemetry->counter('db.queries.duplicated', 'Distinct queries that repeated with identical values within one trace')
                ->inc(1, ['connection' => $event->connectionName]);

            $telemetry->event('db.query.duplicate_detected', [
                'db.system.name' => DbSystem::name($event->connection->getDriverName()),
                'laravel.db.connection' => $event->connectionName,
                'db.query.text' => $event->sql,
                'laravel.db.query.shape' => $this->shape($event),
                'db.query.repeat_count' => $count,
                ...$this->eventOrigin(),
            ]);
        });
    }

    private function reportNPlusOne(TelemetryManager $telemetry, QueryExecuted $event, string $shape, int $count): void
    {
        FailSafe::guard(function () use ($telemetry, $event, $shape, $count) {
            $telemetry->tracer()->bumpStat('db.query.n_plus_one.count', 1);

            $telemetry->counter('db.queries.n_plus_one', 'Distinct query shapes that ran repeatedly with different values within one trace (N+1)')
                ->inc(1, ['connection' => $event->connectionName]);

            $telemetry->event('db.query.n_plus_one_detected', [
                'db.system.name' => DbSystem::name($event->connection->getDriverName()),
                'laravel.db.connection' => $event->connectionName,
                'db.query.text' => $event->sql,
                'laravel.db.query.shape' => $shape,
                'db.query.repeat_count' => $count,
                ...$this->eventOrigin(),
            ]);
        });
    }

    /**
     * @return array<string, string|int>
     */
    private function eventOrigin(): array
    {
        if ($this->origin === self::ORIGIN_OFF) {
            return [];
        }

        return $this->crossingOrigin ??= SourceLocator::locate($this->basePath) ?? [];
    }

    /**
     * A cheap, collision-resistant rendering of the bindings — only ever
     * hashed, never exported. Laravel hands the listener the bindings
     * BEFORE prepareBindings(), so dates, enums and bools arrive as such.
     *
     * @param  array<mixed>  $bindings
     */
    private static function bindingsKey(array $bindings): string
    {
        $key = '';

        foreach ($bindings as $name => $value) {
            $key .= $name.'=';

            $key .= match (true) {
                $value === null => 'n',
                is_bool($value) => $value ? 't' : 'f',
                is_int($value), is_float($value) => 'd'.$value,
                // Length-prefixed, so ('a,b') and ('a', 'b') cannot collide.
                is_string($value) => 's'.strlen($value).':'.$value,
                $value instanceof DateTimeInterface => 'D'.$value->format('Y-m-d H:i:s.uP'),
                $value instanceof BackedEnum => 'e'.$value->value,
                $value instanceof UnitEnum => 'e'.$value->name,
                $value instanceof Stringable => 'S'.$value,
                default => 'o'.get_debug_type($value),
            };

            $key .= ';';
        }

        return $key;
    }
}
