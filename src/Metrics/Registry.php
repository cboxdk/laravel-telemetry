<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Metrics;

use Cbox\Telemetry\Contracts\MetricStore;
use Cbox\Telemetry\Exceptions\GaugeShapeConflict;
use Cbox\Telemetry\Exceptions\InstrumentTypeMismatch;
use Cbox\Telemetry\Metrics\Instruments\Counter;
use Cbox\Telemetry\Metrics\Instruments\Gauge;
use Cbox\Telemetry\Metrics\Instruments\Histogram;
use Cbox\Telemetry\Metrics\Instruments\Observable;
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

    /** @var array<string, Observable> */
    private array $observables = [];

    /** @var array<string, MetricType> */
    private array $types = [];

    /**
     * @param  list<float>  $defaultBuckets
     * @param  (Closure(): ?string)|null  $exemplarTraceId  Resolves the
     *                                                      current sampled
     *                                                      trace id for
     *                                                      histogram
     *                                                      exemplars.
     */
    public function __construct(
        private readonly MetricStore $store,
        private readonly array $defaultBuckets,
        private readonly ?Closure $exemplarTraceId = null,
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
     * @return ($callback is null ? Gauge : Observable)
     */
    public function gauge(
        string $name,
        ?Closure $callback = null,
        string $description = '',
        string $unit = '',
    ): Gauge|Observable {
        if ($callback !== null) {
            return $this->observable($name, $callback, MetricType::Gauge, $description, $unit);
        }

        return $this->pushed($name, MetricType::Gauge, $description, $unit);
    }

    /**
     * A push instrument of any scalar shape — the callback-less form of
     * `gauge()`, with the instrument type spelled out.
     *
     * `set()` is how a value reaches shared storage in a shared-nothing
     * runtime; it says nothing about what the value means. A daemon
     * reading a host's memory writes an absolute number, and that number
     * is a non-monotonic SUM whether it arrived by accumulation or by
     * being read whole. This is how it gets to say so.
     */
    public function pushed(
        string $name,
        MetricType $type = MetricType::Gauge,
        string $description = '',
        string $unit = '',
    ): Gauge {
        if (isset($this->observables[$name])) {
            throw new GaugeShapeConflict($name, existingIsObservable: true);
        }

        return $this->gauges[$name] ??= new Gauge(
            $this->define($name, $type, $description, $unit),
            $this->store,
        );
    }

    /**
     * An observed reading of any shape — the callback form of `gauge()`,
     * with the instrument type spelled out.
     *
     * Reach for this when the thing you are reading is a SUM rather than a
     * level. Bytes of memory in use are an UpDownCounter: adding them
     * across hosts gives the fleet's memory, which is the question, while
     * a gauge invites a backend to average them and answer a tenth of it.
     * Bytes a NIC has carried since boot are a monotonic Counter: only a
     * counter may be rate()'d, and a reboot's reset is only handled
     * correctly for one that says it is one.
     */
    public function observable(
        string $name,
        Closure $callback,
        MetricType $type = MetricType::Gauge,
        string $description = '',
        string $unit = '',
    ): Observable {
        if (isset($this->gauges[$name])) {
            throw new GaugeShapeConflict($name, existingIsObservable: false);
        }

        return $this->observables[$name] ??= new Observable(
            $this->define($name, $type, $description, $unit),
            $callback,
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
            $this->exemplarTraceId,
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
