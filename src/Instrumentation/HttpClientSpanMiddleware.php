<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\Support\HttpMethod;
use Cbox\Telemetry\Support\HttpTransferTimings;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\SpanStatus;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\TransferStats;
use Illuminate\Contracts\Container\Container;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * A client span owned by the Guzzle call it measures.
 *
 * The events cannot do this. Laravel dispatches `RequestSending` from a
 * middleware INSIDE Guzzle's redirect middleware, so it fires once per hop,
 * while `ResponseReceived` is dispatched once per CALL — and nothing on either
 * event says which call a hop belongs to. A listener therefore had to pair
 * them by request identity, which a redirect breaks: every hop but the last
 * was left open, and `Http::pool` interleaves hops with other members so no
 * "continue the span that is open" rule can pick the right one either.
 *
 * Owning the span here removes the pairing problem rather than solving it.
 * This middleware wraps ONE hop: it opens a span, calls the handler below it,
 * and closes that exact span when that hop's own promise settles. Nothing is
 * matched, so nothing can be mismatched, and a redirect is simply two hops
 * that each open and close.
 *
 * The span is DETACHED — it never becomes the ambient context. Several calls
 * are in flight at once in a pool, and a promise can be created under one
 * parent and awaited under another; an ambient span would make each pooled
 * request a child of the one dispatched before it and re-parent whatever ran
 * next. The parent is fixed when the hop starts.
 *
 * `on_stats` is composed rather than replaced: Laravel installs its own to put
 * the `TransferStats` on the response, and only the LAST hop's survives there,
 * so a hop reads its own timings here or not at all.
 */
final class HttpClientSpanMiddleware
{
    public function __construct(private readonly Container $container) {}

    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            $span = FailSafe::guard(fn (): ?Span => $this->start($request));

            if (! $span instanceof Span) {
                return $handler($request, $options);
            }

            $stats = null;
            $options = $this->captureStats($options, $stats);

            // Exactly once, whichever way this hop ends. A cancelled promise
            // can skip the rejection callback, and a handler can throw before
            // returning one at all.
            $settled = false;
            $finish = function (?ResponseInterface $response, ?Throwable $error, bool $rejected = false) use (&$settled, $span, &$stats): void {
                if ($settled) {
                    return;
                }

                $settled = true;

                FailSafe::guard(fn () => $this->end($span, $response, $error, $stats, $rejected));
            };

            try {
                $promise = $handler($request, $options);
            } catch (Throwable $e) {
                $finish(null, $e, rejected: true);

                throw $e;
            }

            return $promise->then(
                function ($response) use ($finish) {
                    $finish($response instanceof ResponseInterface ? $response : null, null);

                    return $response;
                },
                function ($reason) use ($finish) {
                    // Rejected is rejected. A promise may be rejected with
                    // anything — Guzzle's own paths use throwables, but a
                    // string or an array is legal — and recording those as a
                    // success would also hide them from the error-sampling
                    // escape hatch.
                    $finish(null, $reason instanceof Throwable ? $reason : null, rejected: true);

                    // Rejected exactly as it arrived: the caller's error
                    // handling must not change because it was measured.
                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    private function start(RequestInterface $request): ?Span
    {
        $telemetry = $this->telemetry();

        if (! $telemetry->enabled()) {
            return null;
        }

        $uri = $request->getUri();
        $host = $uri->getHost() !== '' ? $uri->getHost() : 'unknown';
        $path = $uri->getPath() !== '' ? $uri->getPath() : '/';

        return $telemetry->tracer()->startDetachedSpan(
            HttpMethod::forSpanName($request->getMethod()).' '.$host,
            SpanKind::Client,
            array_filter([
                'http.request.method' => HttpMethod::normalize($request->getMethod()),
                'http.request.method_original' => HttpMethod::original($request->getMethod()),
                'server.address' => $host,
                'url.path' => $path,
            ], static fn ($value) => $value !== null),
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function captureStats(array $options, ?TransferStats &$stats): array
    {
        $previous = $options['on_stats'] ?? null;

        $options['on_stats'] = function (TransferStats $transfer) use (&$stats, $previous): void {
            $stats = $transfer;

            if (is_callable($previous)) {
                $previous($transfer);
            }
        };

        return $options;
    }

    private function end(Span $span, ?ResponseInterface $response, ?Throwable $error, ?TransferStats $stats, bool $rejected = false): void
    {
        // Where the call actually went. This middleware is registered before
        // the app's own, so it is OUTSIDE them and reads the request before
        // `withRequestMiddleware()` has had its say — a callback rewriting the
        // URI would leave the span naming a host nobody called. The stats
        // carry the request as it went on the wire.
        $sent = $stats?->getRequest();

        if ($sent !== null) {
            $this->correctDestination($span, $sent);
        }

        if ($stats !== null && config('telemetry.instrument.http_client_timing', true)) {
            $span->setAttributes(HttpTransferTimings::attributes($stats->getHandlerStats()));
        }

        $status = $response?->getStatusCode();

        if ($status !== null) {
            $span->setAttribute('http.response.status_code', $status);
        }

        if ($error !== null) {
            $span->recordException($error);
            $span->setStatus(SpanStatus::Error, $error->getMessage());
        } elseif ($rejected) {
            $span->setStatus(SpanStatus::Error, 'the request was rejected');
        } else {
            $span->setStatus($status !== null && $status >= 400 ? SpanStatus::Error : SpanStatus::Ok);
        }

        $span->end();

        $this->recordDuration($span, $status);
    }

    private function correctDestination(Span $span, RequestInterface $sent): void
    {
        $uri = $sent->getUri();
        $host = $uri->getHost() !== '' ? $uri->getHost() : 'unknown';
        $path = $uri->getPath() !== '' ? $uri->getPath() : '/';

        $method = HttpMethod::normalize($sent->getMethod());

        if (($span->attributes()['server.address'] ?? null) === $host
            && ($span->attributes()['url.path'] ?? null) === $path
            && ($span->attributes()['http.request.method'] ?? null) === $method) {
            return;
        }

        // The METHOD too, not only the host. A callback that rewrites the verb
        // as well as the URI left the name saying POST beside an attribute and
        // a metric label saying GET — and a callback that rewrites only the
        // verb was not corrected at all, because the early return above only
        // looked at where the call went.
        $span->setAttributes([
            'server.address' => $host,
            'url.path' => $path,
            'http.request.method' => $method,
            'http.request.method_original' => HttpMethod::original($sent->getMethod()),
        ]);

        $span->updateName(HttpMethod::forSpanName($sent->getMethod()).' '.$host);
    }

    private function recordDuration(Span $span, ?int $status): void
    {
        $telemetry = $this->telemetry();

        // The span keeps the real hostname; the METRIC takes whatever the app
        // says is bounded. Without a classifier this is the hostname, which is
        // only safe while every outbound host is one the app chose.
        [$record, $label] = $telemetry->classifyHttpHost((string) ($span->attributes()['server.address'] ?? 'unknown'));

        if (! $record) {
            return;
        }

        // Seconds, per semconv — see the note on the server-side histogram in
        // TraceRequest. One observation per HOP, because a hop is a request:
        // a call that followed two redirects made three of them.
        $telemetry
            ->histogram('http.client.request.duration', buckets: [0.0005, 0.001, 0.0025, 0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 10], description: 'Outgoing HTTP request duration', unit: 's')
            ->record($span->durationMs() / 1000, [
                'http.request.method' => (string) ($span->attributes()['http.request.method'] ?? HttpMethod::OTHER),
                'server.address' => $label,
                'http.response.status_code' => (string) ($status ?? 0),
            ]);
    }

    private function telemetry(): TelemetryManager
    {
        return $this->container->make(TelemetryManager::class);
    }
}
