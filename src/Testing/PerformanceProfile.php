<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Testing;

use Cbox\Telemetry\Events\TelemetryEvent;
use Cbox\Telemetry\Instrumentation\QueryInstrumentation;
use Cbox\Telemetry\Support\Cast;
use Cbox\Telemetry\Support\SqlShape;
use Cbox\Telemetry\Tracing\Span;
use Closure;
use Illuminate\Container\Container;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;

/**
 * What one unit of work cost — a request's trace, or everything a
 * `Telemetry::budget()` closure ran — read back from the telemetry the
 * package already records, with performance assertions to match.
 *
 * Nothing here measures anything. The query count is the root span's
 * `db.query.count` tally (or the `db.query` spans, whichever saw more —
 * the tally survives `queries_min_duration`, the spans survive a context
 * reset); N+1 and duplicates are what QueryInstrumentation's detector
 * flagged, by SQL shape; origins are the `code.*` attributes it attached.
 * So a test asserts exactly what production would report, and a failure
 * reads like the trace would: shape, count, line of code.
 *
 * ```php
 * $fake->profiles()[0]
 *     ->assertQueryCount('<=', 10)
 *     ->assertNoNPlusOne()
 *     ->assertMaxDuration(200);
 * ```
 */
final readonly class PerformanceProfile
{
    private const N_PLUS_ONE_EVENT = 'db.query.n_plus_one_detected';

    private const DUPLICATE_EVENT = 'db.query.duplicate_detected';

    /** Shapes listed in a failure before the rest are summarised. */
    private const LISTED = 10;

    /**
     * @param  list<Span>  $spans
     * @param  list<TelemetryEvent>  $events
     * @param  float|null  $wallMs  the measured duration, when the profile is not one trace (a budget)
     */
    public function __construct(
        private string $label,
        private array $spans,
        private array $events = [],
        private ?float $wallMs = null,
    ) {}

    /**
     * One profile per trace, in the order the traces started.
     *
     * @param  list<Span>  $spans
     * @param  list<TelemetryEvent>  $events
     * @return list<self>
     */
    public static function perTrace(array $spans, array $events): array
    {
        /** @var array<string, list<Span>> $traces */
        $traces = [];

        foreach ($spans as $span) {
            $traces[$span->traceId][] = $span;
        }

        /** @var array<string, list<TelemetryEvent>> $eventsByTrace */
        $eventsByTrace = [];

        foreach ($events as $event) {
            if ($event->traceId !== null) {
                $eventsByTrace[$event->traceId][] = $event;
            }
        }

        $profiles = [];

        foreach ($traces as $traceId => $traceSpans) {
            $roots = self::rootsOf($traceSpans);
            $profiles[] = [
                'start' => $roots === [] ? 0 : min(array_map(static fn (Span $s): int => $s->startUnixNano(), $roots)),
                'profile' => new self(
                    $roots === [] ? "trace {$traceId}" : $roots[0]->name,
                    $traceSpans,
                    $eventsByTrace[$traceId] ?? [],
                ),
            ];
        }

        usort($profiles, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return array_column($profiles, 'profile');
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * @return list<Span>
     */
    public function spans(): array
    {
        return $this->spans;
    }

    /**
     * @return list<TelemetryEvent>
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * The measured duration of a budget, or the longest root span of a
     * trace — the request as the server saw it.
     */
    public function durationMs(): float
    {
        if ($this->wallMs !== null) {
            return $this->wallMs;
        }

        $longest = 0.0;

        foreach (self::rootsOf($this->spans) as $root) {
            $longest = max($longest, $root->durationMs());
        }

        return $longest;
    }

    /**
     * @return list<Span>
     */
    public function queries(): array
    {
        return array_values(array_filter($this->spans, static fn (Span $span): bool => $span->name === 'db.query'));
    }

    /**
     * How many queries ran. The root tally counts every query, including
     * those under the `queries_min_duration` noise floor that never got a
     * span; the spans count queries whose root never carried the tally
     * (a request reset the context under a budget's span). Whichever saw
     * more saw the truth.
     */
    public function queryCount(): int
    {
        $tally = 0;

        foreach (self::rootsOf($this->spans) as $root) {
            $tally += Cast::int($root->attributes()['db.query.count'] ?? 0);
        }

        return max($tally, count($this->queries()));
    }

    /**
     * Every query, grouped by shape, most frequent first.
     *
     * @return list<QueryGroup>
     */
    public function queryGroups(): array
    {
        return $this->group($this->queries(), withDetections: true);
    }

    /**
     * The shapes the detector flagged as N+1: the same statement with
     * different values, at least `query_duplicates_threshold` times.
     *
     * @return list<QueryGroup>
     */
    public function nPlusOnes(): array
    {
        return array_values(array_filter($this->queryGroups(), static fn (QueryGroup $group): bool => $group->nPlusOne));
    }

    /**
     * The shapes the detector flagged as duplicates: the same statement
     * with the same values, at least `query_duplicates_threshold` times.
     *
     * @return list<QueryGroup>
     */
    public function duplicates(): array
    {
        return array_values(array_filter($this->queryGroups(), static fn (QueryGroup $group): bool => $group->duplicate));
    }

    /**
     * Queries at or over a threshold, grouped by shape.
     *
     * @return list<QueryGroup>
     */
    public function slowQueries(float $thresholdMs): array
    {
        return $this->group(
            array_values(array_filter($this->queries(), static fn (Span $span): bool => $span->durationMs() >= $thresholdMs)),
            withDetections: false,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Assertions
    |--------------------------------------------------------------------------
    */

    /**
     * `assertQueryCount(3)` for exactly three, or with an operator:
     * `assertQueryCount('<=', 10)`. Operators: = == != < <= > >=.
     */
    public function assertQueryCount(int|string $operator, ?int $expected = null): self
    {
        if (is_int($operator)) {
            [$operator, $expected] = ['==', $operator];
        }

        if ($expected === null) {
            throw new InvalidArgumentException('assertQueryCount() needs a count: assertQueryCount(\'<=\', 10).');
        }

        self::requireQueryInstrumentation(__FUNCTION__);

        $actual = $this->queryCount();

        $holds = match ($operator) {
            '=', '==', '===' => $actual === $expected,
            '!=', '!==', '<>' => $actual !== $expected,
            '<' => $actual < $expected,
            '<=' => $actual <= $expected,
            '>' => $actual > $expected,
            '>=' => $actual >= $expected,
            default => throw new InvalidArgumentException("Unknown operator [{$operator}]: use =, !=, <, <=, > or >=."),
        };

        self::check($holds, fn (): string => sprintf(
            '[%s] ran %d database %s; expected %s %d.%s',
            $this->label,
            $actual,
            $actual === 1 ? 'query' : 'queries',
            $operator === '===' ? '==' : $operator,
            $expected,
            $this->listing($this->queryGroups(), 'By shape, most frequent first:', originHint: true),
        ));

        return $this;
    }

    public function assertNoNPlusOne(): self
    {
        self::requireQueryInstrumentation(__FUNCTION__, detection: true);

        $groups = $this->nPlusOnes();

        self::check($groups === [], fn (): string => sprintf(
            '[%s] has %d N+1 query %s — one statement run again and again with different values, '
            .'typically once per row of something loaded earlier. Eager-load the relation (with()/load()) '
            .'or fetch the set with one whereIn().%s',
            $this->label,
            count($groups),
            count($groups) === 1 ? 'pattern' : 'patterns',
            $this->listing($groups, ''),
        ));

        return $this;
    }

    public function assertNoDuplicateQueries(): self
    {
        self::requireQueryInstrumentation(__FUNCTION__, detection: true);

        $groups = $this->duplicates();

        self::check($groups === [], fn (): string => sprintf(
            '[%s] ran %d %s repeatedly with identical values — the answer was already in hand. '
            .'Memoize it, cache it, or pass it down.%s',
            $this->label,
            count($groups),
            count($groups) === 1 ? 'query' : 'queries',
            $this->listing($groups, ''),
        ));

        return $this;
    }

    /**
     * No query at or over `$thresholdMs` — by default the threshold the
     * instrumentation itself calls slow (`traces.details.slow_span_ms`).
     */
    public function assertNoSlowQueries(?float $thresholdMs = null): self
    {
        self::requireQueryInstrumentation(__FUNCTION__);

        $thresholdMs ??= Cast::float(self::config('telemetry.traces.details.slow_span_ms'), 100.0);
        $groups = $this->slowQueries($thresholdMs);

        self::check($groups === [], fn (): string => sprintf(
            '[%s] ran %d slow query %s (at or over %s ms).%s',
            $this->label,
            count($groups),
            count($groups) === 1 ? 'shape' : 'shapes',
            $thresholdMs,
            $this->listing($groups, ''),
        ));

        return $this;
    }

    public function assertMaxDuration(float $ms): self
    {
        $actual = $this->durationMs();
        $holds = $actual <= $ms;

        self::check($holds, fn (): string => sprintf(
            '[%s] took %s ms; the budget is %s ms.%s',
            $this->label,
            round($actual, 2),
            $ms,
            $this->slowestSpans(),
        ));

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Pass (counted as an assertion) or fail with the message — built only
     * on failure, and without PHPUnit's "false is true" trailer.
     *
     * @param  Closure(): string  $message
     */
    private static function check(bool $holds, Closure $message): void
    {
        if (! $holds) {
            Assert::fail($message());
        }

        // Counted, so a test whose only assertion is a budget is not "risky".
        Assert::assertTrue($holds); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }

    /**
     * A query assertion with the query instrumentation off would pass no
     * matter what the code did — the worst possible reason to pass — so it
     * fails instead, and says which switch to flip.
     */
    public static function requireQueryInstrumentation(string $assertion, bool $detection = false): void
    {
        $off = match (true) {
            Cast::flag(self::config('telemetry.enabled'), true) === false => ['telemetry.enabled', 'TELEMETRY_ENABLED'],
            Cast::flag(self::config('telemetry.instrument.queries'), true) === false => ['telemetry.instrument.queries', 'TELEMETRY_INSTRUMENT_QUERIES'],
            $detection && Cast::flag(self::config('telemetry.instrument.query_duplicates'), true) === false => ['telemetry.instrument.query_duplicates', 'TELEMETRY_INSTRUMENT_QUERY_DUPLICATES'],
            default => null,
        };

        if ($off !== null) {
            Assert::fail(sprintf(
                '%s() reads what the query instrumentation records, and [%s] is off in this environment — '
                .'so it would pass no matter what the code did. Set %s=true in phpunit.xml.',
                $assertion,
                $off[0],
                $off[1],
            ));
        }
    }

    /**
     * A config value, when there is an application to ask — the fake also
     * runs in plain package tests with no container at all.
     */
    private static function config(string $key): mixed
    {
        if (! class_exists(Container::class)) {
            return null;
        }

        $container = Container::getInstance();

        if (! $container->bound('config')) {
            return null;
        }

        $config = $container->make('config');

        return is_object($config) && method_exists($config, 'get') ? $config->get($key) : null;
    }

    /**
     * @param  list<Span>  $spans
     * @return list<Span>
     */
    private static function rootsOf(array $spans): array
    {
        $ids = [];

        foreach ($spans as $span) {
            $ids[$span->spanId] = true;
        }

        return array_values(array_filter(
            $spans,
            static fn (Span $span): bool => $span->parentSpanId === null || ! isset($ids[$span->parentSpanId]),
        ));
    }

    /**
     * @param  list<Span>  $spans
     * @return list<QueryGroup>
     */
    private function group(array $spans, bool $withDetections): array
    {
        /** @var array<string, array{sql: string, count: int, ms: float, origin: ?string, n_plus_one: bool, duplicate: bool}> $groups */
        $groups = [];

        foreach ($spans as $span) {
            $attributes = $span->attributes();
            $key = self::shapeKey($attributes);
            $repeat = $attributes['laravel.db.query.repeat'] ?? null;

            $groups[$key] ??= ['sql' => SqlShape::normalize(Cast::string($attributes['db.query.text'] ?? '')), 'count' => 0, 'ms' => 0.0, 'origin' => null, 'n_plus_one' => false, 'duplicate' => false];
            $groups[$key]['count']++;
            $groups[$key]['ms'] += $span->durationMs();
            $groups[$key]['origin'] ??= self::origin($attributes);
            $groups[$key]['n_plus_one'] = $groups[$key]['n_plus_one'] || $repeat === QueryInstrumentation::REPEAT_N_PLUS_ONE;
            $groups[$key]['duplicate'] = $groups[$key]['duplicate'] || $repeat === QueryInstrumentation::REPEAT_DUPLICATE;
        }

        // The detection events are the authority on WHAT was flagged: they
        // fire at the crossing whether or not that query's span survived a
        // noise floor, and they carry the origin of the line that crossed.
        if ($withDetections) {
            foreach ($this->events as $event) {
                if ($event->name !== self::N_PLUS_ONE_EVENT && $event->name !== self::DUPLICATE_EVENT) {
                    continue;
                }

                $key = self::shapeKey($event->attributes);

                $groups[$key] ??= ['sql' => SqlShape::normalize(Cast::string($event->attributes['db.query.text'] ?? '')), 'count' => 0, 'ms' => 0.0, 'origin' => null, 'n_plus_one' => false, 'duplicate' => false];
                $groups[$key]['count'] = max($groups[$key]['count'], Cast::int($event->attributes['db.query.repeat_count'] ?? 0));
                $groups[$key]['origin'] ??= self::origin($event->attributes);
                $groups[$key]['n_plus_one'] = $groups[$key]['n_plus_one'] || $event->name === self::N_PLUS_ONE_EVENT;
                $groups[$key]['duplicate'] = $groups[$key]['duplicate'] || $event->name === self::DUPLICATE_EVENT;
            }
        }

        $result = [];

        foreach ($groups as $shape => $group) {
            $result[] = new QueryGroup((string) $shape, $group['sql'], $group['count'], $group['ms'], $group['origin'], $group['n_plus_one'], $group['duplicate']);
        }

        usort($result, static fn (QueryGroup $a, QueryGroup $b): int => [$b->count, $b->durationMs] <=> [$a->count, $a->durationMs]);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function shapeKey(array $attributes): string
    {
        $shape = Cast::string($attributes['laravel.db.query.shape'] ?? '');

        return $shape !== ''
            ? $shape
            : SqlShape::hash(Cast::string($attributes['laravel.db.connection'] ?? ''), Cast::string($attributes['db.query.text'] ?? ''));
    }

    /**
     * `path:line (function)` from the code.* attributes, or null.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function origin(array $attributes): ?string
    {
        $path = Cast::string($attributes['code.file.path'] ?? '');

        if ($path === '') {
            return null;
        }

        $line = Cast::int($attributes['code.line.number'] ?? 0);
        $function = Cast::string($attributes['code.function.name'] ?? '');

        return $path.($line > 0 ? ":{$line}" : '').($function !== '' ? " ({$function})" : '');
    }

    /**
     * @param  list<QueryGroup>  $groups
     */
    private function listing(array $groups, string $heading, bool $originHint = false): string
    {
        if ($groups === []) {
            return '';
        }

        $lines = array_map(static fn (QueryGroup $group): string => $group->describe(), array_slice($groups, 0, self::LISTED));

        if (count($groups) > self::LISTED) {
            $lines[] = sprintf('  … and %d more %s.', count($groups) - self::LISTED, count($groups) - self::LISTED === 1 ? 'shape' : 'shapes');
        }

        $text = "\n".($heading !== '' ? $heading."\n" : '').implode("\n", $lines);

        $missing = array_filter($groups, static fn (QueryGroup $group): bool => $group->origin === null) !== [];

        if ($originHint && $missing && Cast::string(self::config('telemetry.instrument.query_origin')) !== QueryInstrumentation::ORIGIN_ALL) {
            $text .= "\nOnly flagged (slow or repeated) queries carry an origin; set "
                .'TELEMETRY_INSTRUMENT_QUERY_ORIGIN=all in phpunit.xml to locate every one.';
        }

        return $text;
    }

    /**
     * The five slowest spans under the root(s) — where a blown duration
     * budget went.
     */
    private function slowestSpans(): string
    {
        $roots = array_map(static fn (Span $span): string => $span->spanId, $this->wallMs === null ? self::rootsOf($this->spans) : []);
        $children = array_values(array_filter($this->spans, static fn (Span $span): bool => ! in_array($span->spanId, $roots, true)));

        if ($children === []) {
            return '';
        }

        usort($children, static fn (Span $a, Span $b): int => $b->durationMs() <=> $a->durationMs());

        $lines = array_map(static function (Span $span): string {
            $text = Cast::string($span->attributes()['db.query.text'] ?? '');
            $detail = $text !== '' ? '  '.mb_substr(SqlShape::normalize($text), 0, 160) : '';

            return sprintf('  %9s ms  %s%s', round($span->durationMs(), 2), $span->name, $detail);
        }, array_slice($children, 0, 5));

        return "\nSlowest spans:\n".implode("\n", $lines);
    }
}
