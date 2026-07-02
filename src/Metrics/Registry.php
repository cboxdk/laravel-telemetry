<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Metrics;

use Cbox\Telemetry\Contracts\MetricStore;
use Cbox\Telemetry\Exceptions\GaugeShapeConflict;
use Cbox\Telemetry\Exceptions\InstrumentTypeMismatch;
use Cbox\Telemetry\Metrics\Instruments\Counter;
use Cbox\Telemetry\Metrics\Instruments\Gauge;
use Cbox\Telemetry\Metrics\Instruments\Histogram;
use Cbox\Telemetry\Metrics\Instruments\ObservableGauge;
use Cbox\Telemetry\Support\FailSafe;
use Closure;

/**
 * The instrument registry.
 *
 * Instruments are created lazily and memoized by name. Creating an
 * instrument throws on programmer error (bad name, type conflict);
 * recording through an instrument never throws.
 */
final class Registry
{
    /** @var array<string, Counter> */
    private array $counters = [];

    /** @var array<string, Gauge> */
    private array $gauges = [];

    /** @var array<string, Histogram> */
    private array $histograms = [];

    /** @var array<string, ObservableGauge> */
    private array $observables = [];

    /** @var array<string, MetricType> */
    private array $types = [];

    /**
     * @param  list<float>  $defaultBuckets
     */
    public function __construct(
        private readonly MetricStore $store,
        private readonly array $defaultBuckets,
    ) {}

    public function counter(string $name, string $description = '', string $unit = ''): Counter
    {
        return $this->counters[$name] ??= new Counter(
            $this->define($name, MetricType::Counter, $description, $unit),
            $this->store,
        );
    }

    /**
     * Without a callback: a push gauge you set() at event time.
     * With a callback: an observable gauge evaluated at scrape time.
     *
     * @return ($callback is null ? Gauge : ObservableGauge)
     */
    public function gauge(
        string $name,
        ?Closure $callback = null,
        string $description = '',
        string $unit = '',
    ): Gauge|ObservableGauge {
        if ($callback !== null) {
            if (isset($this->gauges[$name])) {
                throw new GaugeShapeConflict($name, existingIsObservable: false);
            }

            return $this->observables[$name] ??= new ObservableGauge(
                $this->define($name, MetricType::Gauge, $description, $unit),
                $callback,
            );
        }

        if (isset($this->observables[$name])) {
            throw new GaugeShapeConflict($name, existingIsObservable: true);
        }

        return $this->gauges[$name] ??= new Gauge(
            $this->define($name, MetricType::Gauge, $description, $unit),
            $this->store,
        );
    }

    /**
     * @param  list<float>|null  $buckets
     */
    public function histogram(
        string $name,
        ?array $buckets = null,
        string $description = '',
        string $unit = '',
    ): Histogram {
        return $this->histograms[$name] ??= new Histogram(
            $this->define($name, MetricType::Histogram, $description, $unit, $buckets ?? $this->defaultBuckets),
            $this->store,
        );
    }

    /**
     * Every metric family: stored push metrics plus freshly evaluated
     * observable gauges.
     *
     * @return list<MetricFamily>
     */
    public function collect(): array
    {
        $stored = FailSafe::guard(fn (): array => $this->store->collect()) ?? [];

        return [...$stored, ...$this->observe()];
    }

    /**
     * Evaluate observable gauges. A failing callback drops only its own
     * family — never the whole scrape.
     *
     * @return list<MetricFamily>
     */
    public function observe(): array
    {
        $families = [];

        foreach ($this->observables as $observable) {
            $family = FailSafe::guard(fn (): MetricFamily => $observable->observe());

            if ($family !== null) {
                $families[] = $family;
            }
        }

        return $families;
    }

    public function store(): MetricStore
    {
        return $this->store;
    }

    /**
     * @param  list<float>|null  $buckets
     */
    private function define(
        string $name,
        MetricType $type,
        string $description,
        string $unit,
        ?array $buckets = null,
    ): MetricDefinition {
        if (isset($this->types[$name]) && $this->types[$name] !== $type) {
            throw new InstrumentTypeMismatch($name, $this->types[$name], $type);
        }

        $this->types[$name] = $type;

        return new MetricDefinition($name, $type, $description, $unit, $buckets);
    }
}
