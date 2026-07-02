<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Http\Middleware;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\SpanStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Automatic HTTP server instrumentation.
 *
 * Starts a server span (continuing an incoming W3C traceparent when
 * configured), names it "METHOD /route/{pattern}" once routing has
 * resolved, records the request duration histogram, and flushes the
 * span buffer in terminate() — after the response has been sent.
 */
final class TraceRequest
{
    private const SPAN_KEY = 'cbox.telemetry.span';

    public function __construct(private readonly TelemetryManager $telemetry) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->telemetry->enabled()) {
            return $next($request);
        }

        FailSafe::guard(function () use ($request) {
            if (config('telemetry.traces.continue_incoming')) {
                $this->telemetry->continueTrace(
                    $request->headers->get('traceparent'),
                    trustSampling: (bool) config('telemetry.traces.trust_incoming_sampling', true),
                );
            }

            $span = $this->telemetry->tracer()->startSpan(
                $request->method().' '.$request->path(),
                SpanKind::Server,
                [
                    'http.request.method' => $request->method(),
                    'url.path' => '/'.ltrim($request->path(), '/'),
                    'url.scheme' => $request->getScheme(),
                ],
            );

            $request->attributes->set(self::SPAN_KEY, $span);
        });

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $span = $request->attributes->get(self::SPAN_KEY);

        if (! $span instanceof Span) {
            return;
        }

        FailSafe::guard(function () use ($request, $response, $span) {
            $route = $this->routePattern($request);

            $span->updateName($request->method().' '.$route);
            $span->setAttributes([
                'http.route' => $route,
                'http.response.status_code' => $response->getStatusCode(),
            ]);

            // Attribute the request to the authenticated user (resolved by
            // now) — enables per-user trace filtering. Id only, never PII.
            if (config('telemetry.instrument.user', true) && ($user = $request->user()) !== null) {
                $span->setAttribute('enduser.id', (string) $user->getAuthIdentifier());
            }

            if ($response->getStatusCode() >= 500) {
                $span->setStatus(SpanStatus::Error);
            } elseif ($span->status() === SpanStatus::Unset) {
                $span->setStatus(SpanStatus::Ok);
            }

            $span->end();

            $this->telemetry
                ->histogram('http.server.request.duration', description: 'HTTP server request duration', unit: 'ms')
                ->record($span->durationMs(), [
                    // App-defined bounded dimensions (plan, team, …) via
                    // Telemetry::labelRequestsUsing(); core labels win.
                    ...$this->telemetry->resolveRequestLabels($request),
                    'http.request.method' => $request->method(),
                    'http.route' => $route,
                    'http.response.status_code' => (string) $response->getStatusCode(),
                ]);
        });

        $this->telemetry->flush();
        $this->telemetry->resetContext();
    }

    /**
     * The low-cardinality route pattern ("/users/{user}"), falling back
     * to a constant when no route matched.
     */
    private function routePattern(Request $request): string
    {
        $route = $request->route();

        if (is_object($route) && method_exists($route, 'uri')) {
            return '/'.ltrim($route->uri(), '/');
        }

        return '/{unmatched}';
    }
}
