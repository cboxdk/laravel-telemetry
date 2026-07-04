<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Http\Controllers;

use Cbox\Telemetry\Events\TelemetryEvent;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\SpanStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Optional browser/RUM span ingest. The frontend POSTs its own spans
 * (page load, fetch timings, JS errors) here; when the browser also
 * propagates its W3C traceparent to the backend, the two share one trace
 * id — a single end-to-end waterfall.
 *
 * A browser endpoint is world-reachable and cannot hold a secret, so
 * every input is bounded hard: capped span count, strict hex ids, capped
 * names/attributes, sane timestamps. Invalid spans are dropped, not
 * fatal. Values pass through the redaction engine before export.
 */
final class SpanIngestController
{
    private const TRACE_ID = '/^[0-9a-f]{32}$/';

    private const SPAN_ID = '/^[0-9a-f]{16}$/';

    private const MAX_NAME = 255;

    private const MAX_VALUE = 1024;

    public function __invoke(Request $request, TelemetryManager $telemetry): Response
    {
        $config = $request->route()?->defaults['telemetryIngest'] ?? [];

        if (! $telemetry->enabled() || ! ($config['enabled'] ?? false)) {
            abort(404);
        }

        // Head sampling — a cheap flood cap on top of throttling.
        $rate = (float) ($config['sample_rate'] ?? 1.0);
        if ($rate < 1.0 && (mt_rand() / mt_getrandmax()) > $rate) {
            return new Response('', 204);
        }

        FailSafe::guard(function () use ($request, $telemetry, $config) {
            $input = $request->json('spans');

            if (! is_array($input)) {
                return;
            }

            $maxSpans = (int) ($config['max_spans'] ?? 128);
            $maxAttrs = (int) ($config['max_attributes'] ?? 32);
            $now = (int) (microtime(true) * 1e9);

            $spans = [];

            foreach (array_slice($input, 0, $maxSpans) as $raw) {
                $span = self::build(is_array($raw) ? $raw : [], $maxAttrs, $now);

                if ($span !== null) {
                    $spans[] = $span;
                }
            }

            $telemetry->ingestSpans($spans);
        });

        // Analytics events (SPA page views, engagement, custom track()) —
        // re-emitted as unsampled OTLP log records so they are never
        // undercounted, on the same event stream as the server's page views.
        FailSafe::guard(function () use ($request, $telemetry, $config) {
            $input = $request->json('events');

            if (! is_array($input)) {
                return;
            }

            $max = (int) ($config['max_spans'] ?? 128);
            $maxAttrs = (int) ($config['max_attributes'] ?? 32);
            $now = (int) (microtime(true) * 1e9);

            $events = [];

            foreach (array_slice($input, 0, $max) as $raw) {
                $event = self::buildEvent(is_array($raw) ? $raw : [], $maxAttrs, $now);

                if ($event !== null) {
                    $events[] = $event;
                }
            }

            $telemetry->ingestEvents($events);
        });

        return new Response('', 204);
    }

    /**
     * Build a bounded analytics event from an untrusted browser payload.
     *
     * @param  array<mixed>  $raw
     */
    private static function buildEvent(array $raw, int $maxAttrs, int $nowNano): ?TelemetryEvent
    {
        $name = self::str($raw['name'] ?? null);

        if ($name === null) {
            return null;
        }

        // A small, bounded, queryable name — same shape as the server's
        // analytics.page_view.
        $name = (string) preg_replace('/[^a-z0-9._]/', '', strtolower($name));

        if ($name === '') {
            return null;
        }

        $timeNano = self::nanos($raw['time'] ?? null) ?? $nowNano;
        $hourNano = 3_600_000_000_000;
        if ($timeNano < $nowNano - 24 * $hourNano || $timeNano > $nowNano + $hourNano) {
            $timeNano = $nowNano;
        }

        $attributes = self::attributes(is_array($raw['attributes'] ?? null) ? $raw['attributes'] : [], $maxAttrs);
        $attributes['telemetry.stream'] = 'analytics';
        $attributes['analytics.source'] = 'browser';
        $attributes['analytics.event'] = $name;
        $attributes['browser'] = true;

        if (($session = self::str($raw['sessionId'] ?? null)) !== null) {
            $attributes['session.id'] = mb_substr($session, 0, self::MAX_VALUE);
        }

        $traceId = self::str($raw['traceId'] ?? null);
        $traceId = ($traceId !== null && preg_match(self::TRACE_ID, $traceId) === 1) ? $traceId : null;

        return new TelemetryEvent(
            name: 'analytics.'.$name,
            timeUnixNano: $timeNano,
            attributes: $attributes,
            traceId: $traceId,
        );
    }

    /**
     * @param  array<mixed>  $raw
     */
    private static function build(array $raw, int $maxAttrs, int $nowNano): ?Span
    {
        $traceId = self::str($raw['traceId'] ?? null);
        $spanId = self::str($raw['spanId'] ?? null);
        $name = self::str($raw['name'] ?? null);

        if ($traceId === null || $spanId === null || $name === null
            || preg_match(self::TRACE_ID, $traceId) !== 1
            || preg_match(self::SPAN_ID, $spanId) !== 1) {
            return null;
        }

        $parent = self::str($raw['parentSpanId'] ?? null);
        $parent = ($parent !== null && preg_match(self::SPAN_ID, $parent) === 1) ? $parent : null;

        // Timestamps arrive as JS-friendly epoch milliseconds.
        $start = self::nanos($raw['start'] ?? null);
        $end = self::nanos($raw['end'] ?? null);

        if ($start === null || $end === null || $end < $start) {
            return null;
        }

        // Clamp to a sane window — reject clock-skewed or replayed garbage.
        $hourNano = 3_600_000_000_000;
        if ($start < $nowNano - 24 * $hourNano || $start > $nowNano + $hourNano || ($end - $start) > $hourNano) {
            return null;
        }

        $attributes = self::attributes(is_array($raw['attributes'] ?? null) ? $raw['attributes'] : [], $maxAttrs);
        $attributes['browser'] = true;

        $span = new Span(
            traceId: $traceId,
            spanId: $spanId,
            parentSpanId: $parent,
            name: mb_substr($name, 0, self::MAX_NAME),
            kind: self::kind(self::str($raw['kind'] ?? null)),
            sampled: true,
            attributes: $attributes,
            onEnd: static fn (): null => null,
            startUnixNano: $start,
        );

        if (self::str($raw['status'] ?? null) === 'error') {
            $span->setStatus(SpanStatus::Error);
        }

        $span->end($end);

        return $span;
    }

    /**
     * @param  array<mixed>  $raw
     * @return array<string, scalar>
     */
    private static function attributes(array $raw, int $max): array
    {
        $out = [];

        foreach ($raw as $key => $value) {
            if (count($out) >= $max || ! is_string($key)) {
                break;
            }

            if (is_string($value)) {
                $out[mb_substr($key, 0, self::MAX_NAME)] = mb_substr($value, 0, self::MAX_VALUE);
            } elseif (is_int($value) || is_float($value) || is_bool($value)) {
                $out[mb_substr($key, 0, self::MAX_NAME)] = $value;
            }
        }

        return $out;
    }

    private static function kind(?string $kind): SpanKind
    {
        return match ($kind) {
            'client' => SpanKind::Client,
            'server' => SpanKind::Server,
            'producer' => SpanKind::Producer,
            'consumer' => SpanKind::Consumer,
            default => SpanKind::Internal,
        };
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function nanos(mixed $ms): ?int
    {
        return is_int($ms) || (is_float($ms) && is_finite($ms)) ? (int) ($ms * 1_000_000) : null;
    }
}
