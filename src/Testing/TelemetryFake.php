<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Testing;

use Cbox\Telemetry\Events\TelemetryEvent;
use Cbox\Telemetry\Metrics\HistogramSample;
use Cbox\Telemetry\Metrics\MetricType;
use Cbox\Telemetry\Metrics\Registry;
use Cbox\Telemetry\Metrics\Sample;
use Cbox\Telemetry\Metrics\Stores\ArrayMetricStore;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\Tracer;
use Closure;
use PHPUnit\Framework\Assert;

/**
 * The test double behind Telemetry::fake().
 *
 * Metrics land in an in-memory store, every trace is sampled, and flushed
 * spans/events are collected instead of exported — with assertions to
 * match, so packages can test their providers without infrastructure.
 *
 * Span resource measurement is ON, matching the `instrument.resources`
 * default the service provider applies to the real tracer: a fake whose
 * spans lack `php.cpu.time_ms`/`php.memory.delta_bytes` would fail tests
 * for attributes production really does emit. Turn it off — to model
 * `instrument.resources => false` — with
 * `$fake->tracer()->measureSpanResources(false)`.
 *
 * REDACTION DOES NOT RUN HERE. The redaction engine is the last thing
 * that touches a batch before an exporter sees it, and this double
 * collects spans in place of exporting them — so what the assertions
 * below show you is what was RECORDED, not what would be SENT. A test
 * asserting that a secret was redacted will therefore pass whether or
 * not the engine would have caught it, which is the worst possible
 * outcome for that particular test.
 *
 * To assert redaction, use a real manager with a CollectingExporter and
 * flush it: `tests/FailSafety/OutgoingUrlRedactionTest.php` does this.
 */
final class TelemetryFake extends TelemetryManager
{
    private readonly CollectingExporter $collector;

    /**
     * @param  list<float>  $defaultBuckets
     */
    public function __construct(array $defaultBuckets = [0.001, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10])
    {
        $store = new ArrayMetricStore;
        $tracer = new Tracer(sampleRate: 1.0);
        $tracer->measureSpanResources();

        parent::__construct(
            enabled: true,
            registry: new Registry($store, $defaultBuckets),
            tracer: $tracer,
            resource: ['service.name' => 'testing'],
        );

        $this->collector = new CollectingExporter;

        $this->addExporter($this->collector);
    }

    /*
    |--------------------------------------------------------------------------
    | Metric assertions
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, scalar|null>|null  $labels
     */
    public function assertCounterIncremented(string $name, ?array $labels = null): void
    {
        $samples = $this->samples($name, MetricType::Counter);

        Assert::assertNotEmpty($samples, "Counter [{$name}] was never incremented.");

        if ($labels !== null) {
            Assert::assertNotNull(
                $this->matchSample($samples, $labels),
                "Counter [{$name}] was incremented, but never with labels ".json_encode($labels).'.',
            );
        }
    }

    public function assertCounterNotIncremented(string $name): void
    {
        Assert::assertEmpty(
            $this->samples($name, MetricType::Counter),
            "Counter [{$name}] was unexpectedly incremented.",
        );
    }

    /**
     * @param  array<string, scalar|null>  $labels
     */
    public function counterValue(string $name, array $labels = []): float
    {
        $sample = $this->matchSample($this->samples($name, MetricType::Counter), $labels);

        return $sample instanceof Sample ? $sample->value : 0.0;
    }

    /**
     * @param  array<string, scalar|null>|null  $labels
     */
    public function assertGaugeSet(string $name, ?array $labels = null): void
    {
        $samples = $this->samples($name, MetricType::Gauge);

        Assert::assertNotEmpty($samples, "Gauge [{$name}] was never set.");

        if ($labels !== null) {
            Assert::assertNotNull(
                $this->matchSample($samples, $labels),
                "Gauge [{$name}] was set, but never with labels ".json_encode($labels).'.',
            );
        }
    }

    public function assertGaugeNotSet(string $name): void
    {
        Assert::assertEmpty(
            $this->samples($name, MetricType::Gauge),
            "Gauge [{$name}] was unexpectedly set.",
        );
    }

    /**
     * @param  array<string, scalar|null>  $labels
     */
    public function gaugeValue(string $name, array $labels = []): float
    {
        $sample = $this->matchSample($this->samples($name, MetricType::Gauge), $labels);

        return $sample instanceof Sample ? $sample->value : 0.0;
    }

    /**
     * @param  array<string, scalar|null>|null  $labels
     */
    public function assertHistogramRecorded(string $name, ?array $labels = null): void
    {
        $samples = $this->samples($name, MetricType::Histogram);

        Assert::assertNotEmpty($samples, "Histogram [{$name}] never recorded a value.");

        if ($labels !== null) {
            Assert::assertNotNull(
                $this->matchSample($samples, $labels),
                "Histogram [{$name}] recorded values, but never with labels ".json_encode($labels).'.',
            );
        }
    }

    public function assertHistogramNotRecorded(string $name): void
    {
        Assert::assertEmpty(
            $this->samples($name, MetricType::Histogram),
            "Histogram [{$name}] unexpectedly recorded a value.",
        );
    }

    /**
     * @param  array<string, scalar|null>  $labels
     */
    public function histogramCount(string $name, array $labels = []): int
    {
        $sample = $this->matchSample($this->samples($name, MetricType::Histogram), $labels);

        return $sample instanceof HistogramSample ? $sample->count : 0;
    }

    /**
     * The total of everything recorded into a histogram series.
     *
     * `histogramCount()` answers how many observations there were;
     * this answers what they added up to, which is what a test asserting
     * a converted or derived value actually needs.
     *
     * @param  array<string, scalar|null>  $labels
     */
    public function histogramSum(string $name, array $labels = []): float
    {
        $sample = $this->matchSample($this->samples($name, MetricType::Histogram), $labels);

        return $sample instanceof HistogramSample ? $sample->sum : 0.0;
    }

    /**
     * Every metric series recorded so far — the metric counterpart to
     * `recordedSpans()`/`recordedEvents()`.
     *
     * The label-matching assertions above answer "does this series
     * exist?"; this answers "what did the code actually record?", which
     * is the only way to pin a label whose value is itself under test:
     *
     * ```php
     * $fake->recordedMetrics('transit.pairs')
     *     ->assertLabelValues('stage', ['cache', 'provider'])
     *     ->assertCardinalityBelow(50);
     * ```
     */
    public function recordedMetrics(?string $name = null): RecordedMetrics
    {
        $metrics = RecordedMetrics::fromFamilies($this->collect());

        return $name === null ? $metrics : $metrics->forMetric($name);
    }

    /**
     * The distinct values one label was recorded with, sorted.
     *
     * @return list<string>
     */
    public function metricLabelValues(string $name, string $label): array
    {
        return $this->recordedMetrics($name)->labelValues($label);
    }

    /**
     * One metric observed exactly these values for one label.
     *
     * @param  array<array-key, scalar|null>  $expected
     */
    public function assertMetricLabelValues(string $name, string $label, array $expected): void
    {
        $this->recordedMetrics($name)->assertLabelValues($label, $expected);
    }

    /*
    |--------------------------------------------------------------------------
    | Span & event assertions
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Closure(Span): bool|null  $callback
     */
    public function assertSpanRecorded(string $name, ?Closure $callback = null): void
    {
        $spans = $this->recordedSpans($name);

        Assert::assertNotEmpty($spans, "No span named [{$name}] was recorded.");

        if ($callback !== null) {
            foreach ($spans as $span) {
                if ($callback($span)) {
                    return;
                }
            }

            Assert::fail("A span named [{$name}] was recorded, but none matched the given callback.");
        }
    }

    public function assertSpanNotRecorded(string $name): void
    {
        Assert::assertEmpty(
            $this->recordedSpans($name),
            "A span named [{$name}] was unexpectedly recorded.",
        );
    }

    /**
     * @return list<Span>
     */
    public function recordedSpans(?string $name = null): array
    {
        $this->flush();

        $spans = [];

        foreach ($this->collector->batches() as $batch) {
            foreach ($batch->spans as $span) {
                if ($name === null || $span->name === $name) {
                    $spans[] = $span;
                }
            }
        }

        return $spans;
    }

    /**
     * @param  Closure(TelemetryEvent): bool|null  $callback
     */
    public function assertEventEmitted(string $name, ?Closure $callback = null): void
    {
        $events = $this->recordedEvents($name);

        Assert::assertNotEmpty($events, "No event named [{$name}] was emitted.");

        if ($callback !== null) {
            foreach ($events as $event) {
                if ($callback($event)) {
                    return;
                }
            }

            Assert::fail("An event named [{$name}] was emitted, but none matched the given callback.");
        }
    }

    public function assertEventNotEmitted(string $name): void
    {
        Assert::assertEmpty(
            $this->recordedEvents($name),
            "An event named [{$name}] was unexpectedly emitted.",
        );
    }

    /**
     * @return list<TelemetryEvent>
     */
    public function recordedEvents(?string $name = null): array
    {
        $this->flush();

        $events = [];

        foreach ($this->collector->batches() as $batch) {
            foreach ($batch->events as $event) {
                if ($name === null || $event->name === $name) {
                    $events[] = $event;
                }
            }
        }

        return $events;
    }

    /*
    |--------------------------------------------------------------------------
    | Performance assertions
    |--------------------------------------------------------------------------
    |
    | Read from what the instrumentation already records — query spans, the
    | root span's tallies, the N+1/duplicate detector — so a test asserts
    | what production would report. The assertions below apply to EVERY
    | trace recorded so far, one at a time: two requests in one test are
    | two budgets, and a failure names the one that blew it.
    */

    /**
     * One profile per recorded trace, in the order they started.
     *
     * @return list<PerformanceProfile>
     */
    public function profiles(): array
    {
        return PerformanceProfile::perTrace($this->recordedSpans(), $this->recordedEvents());
    }

    /**
     * `assertQueryCount(3)` for exactly three per trace, or with an
     * operator: `assertQueryCount('<=', 10)`.
     */
    public function assertQueryCount(int|string $operator, ?int $expected = null): void
    {
        foreach ($this->profilesOrFail(__FUNCTION__) as $profile) {
            $profile->assertQueryCount($operator, $expected);
        }
    }

    public function assertNoNPlusOne(): void
    {
        foreach ($this->profilesOrFail(__FUNCTION__) as $profile) {
            $profile->assertNoNPlusOne();
        }
    }

    public function assertNoDuplicateQueries(): void
    {
        foreach ($this->profilesOrFail(__FUNCTION__) as $profile) {
            $profile->assertNoDuplicateQueries();
        }
    }

    public function assertNoSlowQueries(?float $thresholdMs = null): void
    {
        foreach ($this->profilesOrFail(__FUNCTION__) as $profile) {
            $profile->assertNoSlowQueries($thresholdMs);
        }
    }

    /**
     * Every trace's root span finished within `$ms` milliseconds.
     */
    public function assertMaxDuration(float $ms): void
    {
        foreach ($this->profilesOrFail(__FUNCTION__) as $profile) {
            $profile->assertMaxDuration($ms);
        }
    }

    /**
     * Run `$callback` and hold everything it did to a budget, as one
     * profile — however many requests or traces it spans.
     *
     * N+1 patterns and duplicate queries fail the budget unless allowed:
     * they are never the intended shape of a code path, and a budget that
     * only counted would wave through the loop that fits under it today.
     *
     * Code outside a request is wrapped in a `telemetry.budget` span, so its
     * queries are recorded too (the query instrumentation ignores queries
     * outside a trace). Returns whatever the callback returns.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function budget(
        Closure $callback,
        ?int $queries = null,
        ?float $ms = null,
        bool $allowNPlusOne = false,
        bool $allowDuplicates = false,
    ): mixed {
        $spansBefore = count($this->recordedSpans());
        $eventsBefore = count($this->recordedEvents());

        $started = hrtime(true);

        $result = $this->currentSpan() === null
            ? $this->span('telemetry.budget', $callback)
            : $callback();

        $wallMs = (hrtime(true) - $started) / 1_000_000;

        $profile = new PerformanceProfile(
            'budget',
            array_slice($this->recordedSpans(), $spansBefore),
            array_slice($this->recordedEvents(), $eventsBefore),
            $wallMs,
        );

        if ($queries !== null) {
            $profile->assertQueryCount('<=', $queries);
        }

        if (! $allowNPlusOne) {
            $profile->assertNoNPlusOne();
        }

        if (! $allowDuplicates) {
            $profile->assertNoDuplicateQueries();
        }

        if ($ms !== null) {
            $profile->assertMaxDuration($ms);
        }

        /** @var TReturn $result */
        return $result;
    }

    /**
     * A budget over nothing would pass for the worst possible reason — no
     * trace was recorded, so the instrumentation never ran — so it fails.
     *
     * @return list<PerformanceProfile>
     */
    private function profilesOrFail(string $assertion): array
    {
        $profiles = $this->profiles();

        if ($profiles === []) {
            Assert::fail(
                "{$assertion}() found no recorded trace to check. Make the request after Telemetry::fake() "
                .'(with telemetry.enabled and telemetry.instrument.requests on), or wrap the code in '
                .'Telemetry::budget(fn () => ...).',
            );
        }

        return $profiles;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @return list<Sample>|list<HistogramSample>
     */
    private function samples(string $name, MetricType $type): array
    {
        // collect() is store families followed by the observable gauges,
        // with any registered provider booted first — so a provider's
        // metrics assert like any other.
        foreach ($this->collect() as $family) {
            if ($family->name() === $name && $family->type() === $type) {
                return $family->samples;
            }
        }

        return [];
    }

    /**
     * @param  list<Sample>|list<HistogramSample>  $samples
     * @param  array<string, scalar|null>  $labels
     */
    private function matchSample(array $samples, array $labels): Sample|HistogramSample|null
    {
        $expected = [];

        foreach ($labels as $key => $value) {
            $expected[$key] = $value === null ? '' : (string) $value;
        }

        ksort($expected);

        foreach ($samples as $sample) {
            $actual = $sample->labels;
            ksort($actual);

            if ($actual === $expected) {
                return $sample;
            }
        }

        return null;
    }
}
