<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Http\Middleware;

use Cbox\Telemetry\Events\TelemetryEvent;
use Cbox\Telemetry\Http\Controllers\SpanIngestController;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\Span;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Exports the spans/events SpanIngestController stashed on the request,
 * after the response has already been sent — terminable middleware, the
 * same timing as the main request span flush (see TraceRequest), so a
 * slow/down OTLP collector never adds curl latency to this
 * world-reachable browser endpoint's response.
 */
final class FlushBrowserIngest
{
    public function __construct(private readonly TelemetryManager $telemetry) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $spans = $this->pendingSpans($request);
        $events = $this->pendingEvents($request);

        if ($spans === [] && $events === []) {
            return;
        }

        FailSafe::guard(function () use ($spans, $events) {
            if ($spans !== []) {
                $this->telemetry->ingestSpans($spans);
            }

            if ($events !== []) {
                $this->telemetry->ingestEvents($events);
            }
        });
    }

    /**
     * @return list<Span>
     */
    private function pendingSpans(Request $request): array
    {
        $raw = $request->attributes->get(SpanIngestController::PENDING_SPANS);

        return is_array($raw) ? array_values(array_filter($raw, static fn ($span): bool => $span instanceof Span)) : [];
    }

    /**
     * @return list<TelemetryEvent>
     */
    private function pendingEvents(Request $request): array
    {
        $raw = $request->attributes->get(SpanIngestController::PENDING_EVENTS);

        return is_array($raw) ? array_values(array_filter($raw, static fn ($event): bool => $event instanceof TelemetryEvent)) : [];
    }
}
