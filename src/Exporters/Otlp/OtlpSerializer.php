<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Exporters\Otlp;

use Cbox\Telemetry\Events\TelemetryEvent;
use Cbox\Telemetry\Metrics\HistogramSample;
use Cbox\Telemetry\Metrics\MetricFamily;
use Cbox\Telemetry\Metrics\MetricType;
use Cbox\Telemetry\Metrics\Sample;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanStatus;

/**
 * Serializes telemetry to OTLP/HTTP JSON (spec: Stable for traces,
 * metrics and logs).
 *
 * Protobuf-JSON mapping rules that are easy to get wrong and are handled
 * here: trace/span ids are HEX strings (not base64), 64-bit integers are
 * JSON strings, enums are integers, field names are lowerCamelCase.
 */
final readonly class OtlpSerializer
{
    private const SCOPE = ['name' => 'cboxdk/laravel-telemetry'];

    /**
     * @param  array<string, scalar>  $resource
     */
    public function __construct(private array $resource) {}

    /**
     * @param  list<Span>  $spans
     * @return array<string, mixed>
     */
    public function traces(array $spans): array
    {
        return [
            'resourceSpans' => [[
                'resource' => ['attributes' => $this->attributes($this->resource)],
                'scopeSpans' => [[
                    'scope' => self::SCOPE,
                    'spans' => array_map(fn (Span $span): array => $this->span($span), $spans),
                ]],
            ]],
        ];
    }

    /**
     * @param  list<MetricFamily>  $families
     * @return array<string, mixed>
     */
    public function metrics(array $families): array
    {
        $now = (string) ((int) (microtime(true) * 1e9));

        return [
            'resourceMetrics' => [[
                'resource' => ['attributes' => $this->attributes($this->resource)],
                'scopeMetrics' => [[
                    'scope' => self::SCOPE,
                    'metrics' => array_map(fn (MetricFamily $family): array => $this->metric($family, $now), $families),
                ]],
            ]],
        ];
    }

    /**
     * @param  list<TelemetryEvent>  $events
     * @return array<string, mixed>
     */
    public function logs(array $events): array
    {
        return [
            'resourceLogs' => [[
                'resource' => ['attributes' => $this->attributes($this->resource)],
                'scopeLogs' => [[
                    'scope' => self::SCOPE,
                    'logRecords' => array_map(fn (TelemetryEvent $event): array => $this->logRecord($event), $events),
                ]],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function span(Span $span): array
    {
        $data = [
            'traceId' => $span->traceId,
            'spanId' => $span->spanId,
            'name' => $span->name,
            'kind' => $span->kind->value,
            'startTimeUnixNano' => (string) $span->startUnixNano(),
            'endTimeUnixNano' => (string) $span->endUnixNano(),
            'attributes' => $this->attributes($span->attributes()),
        ];

        if ($span->parentSpanId !== null) {
            $data['parentSpanId'] = $span->parentSpanId;
        }

        if ($span->status() !== SpanStatus::Unset) {
            $data['status'] = array_filter([
                'code' => $span->status()->value,
                'message' => $span->statusDescription(),
            ], static fn ($value) => $value !== null);
        }

        if ($span->events() !== []) {
            $data['events'] = array_map(fn ($event): array => [
                'name' => $event->name,
                'timeUnixNano' => (string) $event->timeUnixNano,
                'attributes' => $this->attributes($event->attributes),
            ], $span->events());
        }

        if ($span->links() !== []) {
            $data['links'] = array_map(fn ($link): array => array_filter([
                'traceId' => $link->traceId,
                'spanId' => $link->spanId,
                'attributes' => $this->attributes($link->attributes) ?: null,
            ], static fn ($value) => $value !== null), $span->links());
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function metric(MetricFamily $family, string $now): array
    {
        $definition = $family->definition;

        $metric = [
            'name' => $definition->name,
            'description' => $definition->description,
            'unit' => $definition->unit,
        ];

        return match ($family->type()) {
            MetricType::Counter => $metric + ['sum' => [
                'aggregationTemporality' => 2, // cumulative — exported from shared storage
                'isMonotonic' => true,
                'dataPoints' => $this->numberDataPoints($family, $now),
            ]],
            MetricType::Gauge => $metric + ['gauge' => [
                'dataPoints' => $this->numberDataPoints($family, $now),
            ]],
            MetricType::Histogram => $metric + ['histogram' => [
                'aggregationTemporality' => 2,
                'dataPoints' => $this->histogramDataPoints($family, $now),
            ]],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function numberDataPoints(MetricFamily $family, string $now): array
    {
        $points = [];

        foreach ($family->samples as $sample) {
            if (! $sample instanceof Sample) {
                continue;
            }

            $point = [
                'attributes' => $this->attributes($sample->labels),
                'timeUnixNano' => $now,
                'asDouble' => $this->finite($sample->value),
            ];

            if ($family->startUnixNano !== null) {
                // Cumulative start — lets backends (Mimir, collectors)
                // detect counter resets.
                $point['startTimeUnixNano'] = (string) $family->startUnixNano;
            }

            $points[] = $point;
        }

        return $points;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function histogramDataPoints(MetricFamily $family, string $now): array
    {
        $points = [];

        foreach ($family->samples as $sample) {
            if (! $sample instanceof HistogramSample) {
                continue;
            }

            $point = [
                'attributes' => $this->attributes($sample->labels),
                'timeUnixNano' => $now,
                'count' => (string) $sample->count,
                'sum' => $this->finite($sample->sum),
                'bucketCounts' => array_map(static fn (int $count): string => (string) $count, $sample->bucketCounts),
                'explicitBounds' => $sample->bounds,
            ];

            if ($family->startUnixNano !== null) {
                $point['startTimeUnixNano'] = (string) $family->startUnixNano;
            }

            $points[] = $point;
        }

        return $points;
    }

    /**
     * NAN/INF are not JSON-encodable — one poisoned value must never
     * drop an entire batch.
     */
    private function finite(float $value): float
    {
        return is_finite($value) ? $value : 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    private function logRecord(TelemetryEvent $event): array
    {
        $record = [
            'timeUnixNano' => (string) $event->timeUnixNano,
            'severityNumber' => $event->severityNumber ?? 9, // default INFO
            'severityText' => $event->severityText ?? 'INFO',
            'body' => ['stringValue' => $event->name],
            'attributes' => $this->attributes($event->attributes),
        ];

        if ($event->traceId !== null) {
            $record['traceId'] = $event->traceId;
        }

        if ($event->spanId !== null) {
            $record['spanId'] = $event->spanId;
        }

        return $record;
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     * @return list<array{key: string, value: array<string, mixed>}>
     */
    private function attributes(array $attributes): array
    {
        $result = [];

        foreach ($attributes as $key => $value) {
            $result[] = ['key' => $key, 'value' => $this->anyValue($value)];
        }

        return $result;
    }

    /**
     * @param  scalar|null  $value
     * @return array<string, mixed>
     */
    private function anyValue(mixed $value): array
    {
        return match (true) {
            is_bool($value) => ['boolValue' => $value],
            is_int($value) => ['intValue' => (string) $value],
            is_float($value) && is_finite($value) => ['doubleValue' => $value],
            is_float($value) => ['stringValue' => (string) $value], // NAN/INF break JSON
            default => ['stringValue' => $value === null ? '' : (string) $value],
        };
    }
}
