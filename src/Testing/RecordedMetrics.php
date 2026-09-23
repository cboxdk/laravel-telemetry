<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Testing;

use ArrayIterator;
use Cbox\Telemetry\Metrics\MetricFamily;
use Cbox\Telemetry\Metrics\MetricType;
use Closure;
use Countable;
use IteratorAggregate;
use PHPUnit\Framework\Assert;
use Traversable;

/**
 * Every metric series the fake recorded, as an inspectable, filterable
 * set — the metric counterpart to `recordedSpans()`/`recordedEvents()`.
 *
 * The name/labels assertions on `TelemetryFake` answer "does this exact
 * series exist?", which is the wrong question whenever the labels are the
 * thing under test: every branch of an attribution produces *a* valid
 * label value, so a test that only checks membership passes on one branch
 * and misses the rest. This object answers the other question —
 * which values did the code actually produce, and how many series did it
 * create:
 *
 * ```php
 * $fake->recordedMetrics('transit.pairs')
 *     ->assertLabelValues('stage', ['cache', 'estimated', 'provider'])
 *     ->assertCardinalityBelow(50);
 * ```
 *
 * Filters return a new instance; assertions return `$this`, so queries and
 * assertions chain in one expression.
 *
 * @implements IteratorAggregate<int, RecordedSample>
 */
final readonly class RecordedMetrics implements Countable, IteratorAggregate
{
    /**
     * @param  list<RecordedSample>  $samples
     */
    public function __construct(private array $samples = []) {}

    /**
     * @param  list<MetricFamily>  $families
     */
    public static function fromFamilies(array $families): self
    {
        $samples = [];

        foreach ($families as $family) {
            foreach ($family->samples as $sample) {
                $samples[] = RecordedSample::from($family, $sample);
            }
        }

        return new self($samples);
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /**
     * @return list<RecordedSample>
     */
    public function samples(): array
    {
        return $this->samples;
    }

    /**
     * The distinct metric names present, sorted.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = [];

        foreach ($this->samples as $sample) {
            $names[$sample->name] = true;
        }

        $names = array_map(strval(...), array_keys($names));
        sort($names);

        return $names;
    }

    /**
     * The distinct values observed for one label, sorted. Series without
     * the label contribute nothing.
     *
     * @return list<string>
     */
    public function labelValues(string $key): array
    {
        $values = [];

        foreach ($this->samples as $sample) {
            $value = $sample->label($key);

            if ($value !== null) {
                $values[$value] = true;
            }
        }

        $values = array_map(strval(...), array_keys($values));
        sort($values);

        return $values;
    }

    /**
     * Every recorded labelset, in recording order.
     *
     * @return list<array<string, string>>
     */
    public function labelSets(): array
    {
        return array_map(fn (RecordedSample $sample): array => $sample->labels, $this->samples);
    }

    /**
     * Distinct values per label key, worst offender first — the shape of
     * a cardinality problem.
     *
     * @return array<string, int>
     */
    public function labelCardinality(): array
    {
        /** @var array<string, array<string, true>> $seen */
        $seen = [];

        foreach ($this->samples as $sample) {
            foreach ($sample->labels as $key => $value) {
                $seen[$key][$value] = true;
            }
        }

        $counts = array_map(count(...), $seen);

        arsort($counts);

        return $counts;
    }

    /**
     * How many distinct series were recorded — the cardinality of this
     * set.
     */
    public function seriesCount(): int
    {
        return count($this->samples);
    }

    public function count(): int
    {
        return count($this->samples);
    }

    public function isEmpty(): bool
    {
        return $this->samples === [];
    }

    /**
     * The sum of every series' value — a counter's grand total across all
     * of its labelsets.
     */
    public function total(): float
    {
        $total = 0.0;

        foreach ($this->samples as $sample) {
            $total += $sample->value;
        }

        return $total;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->samples);
    }

    /*
    |--------------------------------------------------------------------------
    | Filtering
    |--------------------------------------------------------------------------
    */

    public function forMetric(string $name): self
    {
        return $this->filter(fn (RecordedSample $sample): bool => $sample->name === $name);
    }

    public function ofType(MetricType $type): self
    {
        return $this->filter(fn (RecordedSample $sample): bool => $sample->type === $type);
    }

    /**
     * Series carrying (at least) these labels.
     *
     * @param  array<string, scalar|null>  $labels
     */
    public function withLabels(array $labels): self
    {
        return $this->filter(fn (RecordedSample $sample): bool => $sample->hasLabels($labels));
    }

    /**
     * @param  Closure(RecordedSample): bool  $callback
     */
    public function filter(Closure $callback): self
    {
        return new self(array_values(array_filter($this->samples, $callback)));
    }

    /*
    |--------------------------------------------------------------------------
    | Assertions
    |--------------------------------------------------------------------------
    */

    /**
     * The observed values of one label are exactly these — no more, no
     * fewer.
     *
     * This is the assertion that fails when an attribution collapses onto
     * a single branch; `assertCounterIncremented($name, $labels)` cannot,
     * because one matching series is enough to satisfy it.
     *
     * Every series in the set must carry the label. "Exactly these stage
     * values" is a lie by omission if some series have no `stage` at all,
     * and a metric whose series disagree about their label keys is a
     * modelling problem of its own — so that fails here rather than
     * passing quietly. Scope the set with `withLabels()` or `filter()`
     * when a label really is optional.
     *
     * @param  array<array-key, scalar|null>  $expected
     */
    public function assertLabelValues(string $key, array $expected): self
    {
        $missingKey = $this->filter(
            fn (RecordedSample $sample): bool => $sample->label($key) === null,
        )->samples();

        if ($missingKey !== []) {
            Assert::fail(
                $this->subject().' has '.count($missingKey).' of '.$this->seriesCount().
                " series with no [{$key}] label at all, so its observed values cannot be pinned: ".
                implode('; ', array_map(
                    fn (RecordedSample $sample): string => $sample->describe(),
                    array_slice($missingKey, 0, 3),
                )).'. Scope the set with withLabels() or filter() if the label is optional.',
            );
        }

        $wanted = [];

        foreach ($expected as $value) {
            $wanted[] = $value === null ? '' : (string) $value;
        }

        $wanted = array_values(array_unique($wanted));
        sort($wanted);

        $actual = $this->labelValues($key);

        $detail = [];

        foreach (['never observed' => array_diff($wanted, $actual), 'unexpected' => array_diff($actual, $wanted)] as $label => $values) {
            if ($values !== []) {
                $detail[] = $label.': '.implode(', ', $values);
            }
        }

        Assert::assertSame(
            $wanted,
            $actual,
            $this->subject()." observed label [{$key}] values [".implode(', ', $actual).
            '], expected exactly ['.implode(', ', $wanted).'] ('.implode('; ', $detail).').',
        );

        return $this;
    }

    /**
     * Exactly this many series — the blunt cardinality assertion.
     */
    public function assertSeriesCount(int $expected): self
    {
        Assert::assertSame(
            $expected,
            $this->seriesCount(),
            $this->subject().' recorded '.$this->seriesCount()." series, expected {$expected}.".$this->breakdown(),
        );

        return $this;
    }

    /**
     * Cardinality stayed under budget. A series that exploded fails here
     * long before it reaches Prometheus.
     */
    public function assertCardinalityBelow(int $limit): self
    {
        $this->assertSomethingWasRecorded('a cardinality budget');

        Assert::assertLessThan(
            $limit,
            $this->seriesCount(),
            $this->subject().' recorded '.$this->seriesCount().
            " series, which is not below the budget of {$limit}.".$this->breakdown(),
        );

        return $this;
    }

    /**
     * One label stayed under budget — usually the tenant/user/id that
     * should never have been a label in the first place.
     */
    public function assertLabelCardinalityBelow(string $key, int $limit): self
    {
        $this->assertSomethingWasRecorded("a cardinality budget on label [{$key}]");

        $observed = count($this->labelValues($key));

        Assert::assertLessThan(
            $limit,
            $observed,
            $this->subject()." observed {$observed} distinct values for label [{$key}], ".
            "which is not below the budget of {$limit}.",
        );

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * A budget on an empty set passes for the worst possible reason: the
     * code under test recorded nothing at all. That is the silent pass
     * this whole object exists to remove, so it fails instead. Assert the
     * absence itself with `assertSeriesCount(0)` or
     * `assertCounterNotIncremented()` when that is what you mean.
     */
    private function assertSomethingWasRecorded(string $what): void
    {
        Assert::assertNotSame(
            [],
            $this->samples,
            $this->subject()." recorded no series at all, so {$what} proves nothing. ".
            'Assert the absence with assertSeriesCount(0) if that is what you mean.',
        );
    }

    private function subject(): string
    {
        $names = $this->names();

        return match (count($names)) {
            0 => 'No metric',
            1 => "Metric [{$names[0]}]",
            default => 'Metrics ['.implode(', ', $names).']',
        };
    }

    private function breakdown(): string
    {
        $cardinality = $this->labelCardinality();

        if ($cardinality === []) {
            return '';
        }

        $parts = [];

        foreach ($cardinality as $key => $values) {
            $parts[] = "{$key} ({$values})";
        }

        return ' Distinct values per label: '.implode(', ', $parts).'.';
    }
}
