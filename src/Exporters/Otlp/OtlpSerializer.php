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
     * Bound transport requests without splitting a histogram or changing its
     * observation time. Expanded histogram labels can dwarf the JSON body.
     * One indivisible oversized point remains visible to the receiver's limit.
     *
     * @param  list<MetricFamily>  $families
     * @return list<array<string, mixed>>
     */
    public function metricBatches(array $families): array
    {
        $now = (string) ((int) (microtime(true) * 1e9));
        $resource = ['attributes' => $this->attributes($this->resource)];
        $resourceBytes = strlen(json_encode($resource, JSON_THROW_ON_ERROR));
        $batches = $metrics = [];
        $points = $bytes = 0;
        $wrap = static fn (array $items): array => ['resourceMetrics' => [[
            'resource' => $resource,
            'scopeMetrics' => [['scope' => self::SCOPE, 'metrics' => $items]],
        ]]];

        foreach ($families as $family) {
            $metric = $this->metricMetadata($family);
            $kind = match ($family->type()) {
                MetricType::Counter, MetricType::UpDownCounter => 'sum',
                MetricType::Gauge => 'gauge',
                MetricType::Histogram => 'histogram',
            };
            $data = $this->metricData($family, $now);
            $samples = $data['dataPoints'];
            $data['dataPoints'] = [];
            $index = null;

            foreach ($samples as $sample) {
                $buckets = $sample['bucketCounts'] ?? null;
                $expanded = is_array($buckets) ? count($buckets) + 2 : 1;
                $cost = (strlen(json_encode($sample, JSON_THROW_ON_ERROR)) + $resourceBytes + 1024) * $expanded;
                if ($points > 0 && ($points >= 256 || $bytes + $cost > 8 * 1024 * 1024)) {
                    $batches[] = $wrap($metrics);
                    $metrics = [];
                    $points = $bytes = 0;
                    $index = null;
                }
                if ($index === null) {
                    $index = count($metrics);
                    $data['dataPoints'] = [];
                    $metrics[] = $metric;
                }
                $data['dataPoints'][] = $sample;
                $metrics[$index] = $metric + [$kind => $data];
                $points++;
                $bytes += $cost;
            }
        }
        if ($metrics !== []) {
            $batches[] = $wrap($metrics);
        }

        return $batches;
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

        // OTLP's own fields for this. Without them a span that hit its
        // limit is indistinguishable from one that had little to say,
        // and the operator debugs the wrong thing.
        if ($span->droppedAttributes() > 0) {
            $data['droppedAttributesCount'] = $span->droppedAttributes();
        }

        if ($span->droppedEvents() > 0) {
            $data['droppedEventsCount'] = $span->droppedEvents();
        }

        if ($span->status() !== SpanStatus::Unset) {
            $data['status'] = array_filter([
                'code' => $span->status()->value,
                'message' => $span->statusDescription(),
            ], static fn ($value) => $value !== null);
        }

        if ($span->events() !== []) {
            $data['events'] = array_map(fn ($event): array => array_filter([
                'name' => $event->name,
                'timeUnixNano' => (string) $event->timeUnixNano,
                'attributes' => $this->attributes($event->attributes),
                'droppedAttributesCount' => $event->droppedAttributes ?: null,
            ], static fn ($value) => $value !== null), $span->events());
        }

        if ($span->links() !== []) {
            $data['links'] = array_map(fn ($link): array => array_filter([
                'traceId' => $link->traceId,
                'spanId' => $link->spanId,
                'attributes' => $this->attributes($link->attributes) ?: null,
                // W3C trace flags (fixed32, a JSON number): the sampled bit
                // of the linked context, when the link knows it.
                'flags' => $link->sampled === null ? null : ($link->sampled ? 1 : 0),
            ], static fn ($value) => $value !== null), $span->links());
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function metric(MetricFamily $family, string $now): array
    {
        $kind = match ($family->type()) {
            MetricType::Counter, MetricType::UpDownCounter => 'sum',
            MetricType::Gauge => 'gauge',
            MetricType::Histogram => 'histogram',
        };

        return $this->metricMetadata($family) + [$kind => $this->metricData($family, $now)];
    }

    /** @return array{name: string, description: string, unit: string} */
    private function metricMetadata(MetricFamily $family): array
    {
        return [
            'name' => $family->definition->name,
            'description' => $family->definition->description,
            'unit' => $family->definition->unit,
        ];
    }

    /** @return array{dataPoints: list<array<string, mixed>>, aggregationTemporality?: int, isMonotonic?: bool} */
    private function metricData(MetricFamily $family, string $now): array
    {
        return match ($family->type()) {
            MetricType::Counter, MetricType::UpDownCounter => [
                'aggregationTemporality' => 2, // cumulative — exported from shared storage
                // UpDownCounter may decrease: aggregate across hosts, never rate().
                'isMonotonic' => $family->type() === MetricType::Counter,
                'dataPoints' => $this->numberDataPoints($family, $now),
            ],
            MetricType::Gauge => ['dataPoints' => $this->numberDataPoints($family, $now)],
            MetricType::Histogram => [
                'aggregationTemporality' => 2,
                'dataPoints' => $this->histogramDataPoints($family, $now),
            ],
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
                'timeUnixNano' => $family->timeUnixNano === null ? $now : (string) $family->timeUnixNano,
                'asDouble' => $this->jsonDouble($sample->value),
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
                'timeUnixNano' => $family->timeUnixNano === null ? $now : (string) $family->timeUnixNano,
                'count' => (string) $sample->count,
                'sum' => $this->jsonDouble($sample->sum),
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

    /** OTLP follows ProtoJSON for special doubles; missing is never a measured zero. */
    private function jsonDouble(float $value): float|string
    {
        return match (true) {
            is_nan($value) => 'NaN',
            $value === INF => 'Infinity',
            $value === -INF => '-Infinity',
            default => $value,
        };
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
