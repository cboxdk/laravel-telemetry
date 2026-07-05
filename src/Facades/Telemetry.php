<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Facades;

use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Testing\TelemetryFake;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Cbox\Telemetry\Metrics\Instruments\Counter counter(string $name, string $description = '', string $unit = '')
 * @method static \Cbox\Telemetry\Metrics\Instruments\Gauge|\Cbox\Telemetry\Metrics\Instruments\ObservableGauge gauge(string $name, \Closure|null $callback = null, string $description = '', string $unit = '')
 * @method static \Cbox\Telemetry\Metrics\Instruments\Histogram histogram(string $name, list<float>|null $buckets = null, string $description = '', string $unit = '')
 * @method static mixed span(string $name, \Closure|null $callback = null, array<string, scalar|null> $attributes = [], \Cbox\Telemetry\Tracing\SpanKind $kind = \Cbox\Telemetry\Tracing\SpanKind::Internal)
 * @method static void event(string $name, array<string, scalar|null> $attributes = [])
 * @method static \Cbox\Telemetry\Tracing\Span|null currentSpan()
 * @method static string|null traceId()
 * @method static string|null traceparent()
 * @method static void continueTrace(string|null $traceparent)
 * @method static void resetContext()
 * @method static void context(array<string, scalar|null> $attributes)
 * @method static array<string, scalar|null> contextAttributes()
 * @method static void labelRequestsUsing(\Closure|null $resolver)
 * @method static void nameRequestsUsing(\Closure|null $resolver)
 * @method static void resolveRouteUsing(\Closure|null $resolver)
 * @method static void enrichRequestsUsing(\Closure|null $resolver)
 * @method static void classifyCacheKeysUsing(\Closure|null $classifier)
 * @method static void resolveUserUsing(\Closure|null $resolver)
 * @method static void resolveSessionUsing(\Closure|null $resolver)
 * @method static void resolveClientGeoUsing(\Closure|null $resolver)
 * @method static void redactUsing(\Closure|null $hook)
 * @method static void provider(\Cbox\Telemetry\Contracts\TelemetryProvider $provider)
 * @method static void contributes(string $name, \Closure $register)
 * @method static void addExporter(\Cbox\Telemetry\Contracts\Exporter $exporter)
 * @method static void flush()
 * @method static void flushMetrics()
 * @method static void ingestSpans(list<\Cbox\Telemetry\Tracing\Span> $spans)
 * @method static void ingestEvents(list<\Cbox\Telemetry\Events\TelemetryEvent> $events)
 * @method static list<\Cbox\Telemetry\Metrics\MetricFamily> collect()
 * @method static bool enabled()
 * @method static \Cbox\Telemetry\Metrics\Registry registry()
 * @method static void handleExceptionsUsing(\Closure|null $handler)
 *
 * @see TelemetryManager
 */
final class Telemetry extends Facade
{
    /**
     * Swap the manager for an in-memory fake with assertions.
     */
    public static function fake(): TelemetryFake
    {
        $fake = new TelemetryFake;

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return TelemetryManager::class;
    }
}
