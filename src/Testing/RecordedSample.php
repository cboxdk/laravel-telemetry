<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Testing;

use Cbox\Telemetry\Metrics\HistogramSample;
use Cbox\Telemetry\Metrics\MetricFamily;
use Cbox\Telemetry\Metrics\MetricType;
use Cbox\Telemetry\Metrics\Sample;

/**
 * One recorded metric series, flattened for inspection: the metric it
 * belongs to, the labels that identify it, and its value.
 *
 * The span/event side of the fake hands tests the recorded object itself;
 * this is the metric equivalent, so "which label values did this counter
 * actually observe?" is answerable without reaching into `collect()`.
 */
final readonly class RecordedSample
{
    /**
     * @param  array<string, string>  $labels
     * @param  float  $value  Counter/gauge value — for a histogram, the sum
     *                        of the observations.
     * @param  int|null  $count  Observation count, histograms only.
     */
    public function __construct(
        public string $name,
        public MetricType $type,
        public array $labels,
        public float $value,
        public ?int $count = null,
        public ?HistogramSample $histogram = null,
    ) {}

    public static function from(MetricFamily $family, Sample|HistogramSample $sample): self
    {
        if ($sample instanceof HistogramSample) {
            return new self(
                name: $family->name(),
                type: $family->type(),
                labels: $sample->labels,
                value: $sample->sum,
                count: $sample->count,
                histogram: $sample,
            );
        }

        return new self(
            name: $family->name(),
            type: $family->type(),
            labels: $sample->labels,
            value: $sample->value,
        );
    }

    public function label(string $key): ?string
    {
        return $this->labels[$key] ?? null;
    }

    /**
     * Does this series carry (at least) the given labels?
     *
     * @param  array<string, scalar|null>  $labels
     */
    public function hasLabels(array $labels): bool
    {
        foreach ($labels as $key => $value) {
            if (($this->labels[$key] ?? null) !== ($value === null ? '' : (string) $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * `orders.created{tenant="acme"} 2` — used in assertion messages.
     */
    public function describe(): string
    {
        $pairs = [];

        foreach ($this->labels as $key => $value) {
            $pairs[] = $key.'="'.$value.'"';
        }

        $series = $this->name.($pairs === [] ? '' : '{'.implode(',', $pairs).'}');

        return $this->count === null
            ? $series.' '.$this->value
            : $series.' count='.$this->count.' sum='.$this->value;
    }
}
