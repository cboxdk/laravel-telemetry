<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Contracts\ManagesRequestState;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\Support\HttpMethod;
use Cbox\Telemetry\Support\HttpTransferTimings;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\SpanStatus;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;

/**
 * Outgoing HTTP instrumentation: a client span per Http-client request,
 * a duration histogram by peer host/method/status, and a connection-
 * failure counter. Attributes carry host + path — never the query string
 * (tokens live there).
 */
final class HttpClientInstrumentation implements ManagesRequestState
{
    /** @var array<int, Span> keyed by request object id */
    private array $inFlight = [];

    public function __construct(private readonly Container $container) {}

    private function telemetry(): TelemetryManager
    {
        return $this->container->make(TelemetryManager::class);
    }

    public function register(Dispatcher $events): void
    {
        $events->listen(RequestSending::class, $this->sending(...));
        $events->listen(ResponseReceived::class, $this->received(...));
        $events->listen(ConnectionFailed::class, $this->failed(...));
    }

    private function sending(RequestSending $event): void
    {
        FailSafe::guard(function () use ($event) {
            $host = (string) (parse_url($event->request->url(), PHP_URL_HOST) ?: 'unknown');
            $path = (string) (parse_url($event->request->url(), PHP_URL_PATH) ?: '/');

            $this->inFlight[$this->keyFor($event->request)] = $this->telemetry()->tracer()->startSpan(
                HttpMethod::forSpanName($event->request->method()).' '.$host,
                SpanKind::Client,
                [
                    'http.request.method' => HttpMethod::normalize($event->request->method()),
                    'http.request.method_original' => HttpMethod::original($event->request->method()),
                    'server.address' => $host,
                    'url.path' => $path,
                ],
            );
        });
    }

    private function received(ResponseReceived $event): void
    {
        FailSafe::guard(function () use ($event) {
            $span = $this->pull($event->request);

            if ($span !== null) {
                $span->setAttribute('http.response.status_code', $event->response->status());

                // What the 284ms was actually spent on. Laravel's HTTP client
                // already keeps cURL's transfer stats on the response, so this
                // costs one array read — no `on_stats` to install, no option
                // for the caller to remember, and nothing at all when the
                // handler is not cURL.
                //
                // Guarded SEPARATELY from the rest of this listener, not by
                // the outer guard. An app's own `on_stats` callback can return
                // anything, and Laravel keeps whatever it returns — so
                // `handlerStats()` can throw on something that is not a
                // TransferStats at all. Caught out here, that would take
                // setStatus(), end() and the duration histogram with it, and
                // leave the span open for every later span to nest under.
                // Enrichment must never be able to cost the measurement.
                if (config('telemetry.instrument.http_client_timing', true)) {
                    FailSafe::guard(fn () => $span->setAttributes(
                        HttpTransferTimings::attributes($event->response->handlerStats()),
                    ));
                }

                $span->setStatus($event->response->status() >= 400 ? SpanStatus::Error : SpanStatus::Ok);
                $span->end();

                // The span keeps the real hostname; the METRIC takes whatever
                // the app says is bounded. Without a classifier this is the
                // hostname, which is only safe while every outbound host is
                // one the app chose.
                [$record, $label] = $this->telemetry()->classifyHttpHost(
                    (string) $span->attributes()['server.address'],
                );

                if ($record) {
                    // Seconds, per semconv — see the note on the server-side
                    // histogram in TraceRequest.
                    $this->telemetry()
                        ->histogram('http.client.request.duration', buckets: [0.0005, 0.001, 0.0025, 0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 10], description: 'Outgoing HTTP request duration', unit: 's')
                        ->record($span->durationMs() / 1000, [
                            'http.request.method' => HttpMethod::normalize($event->request->method()),
                            'server.address' => $label,
                            'http.response.status_code' => (string) $event->response->status(),
                        ]);
                }
            }
        });
    }

    private function failed(ConnectionFailed $event): void
    {
        FailSafe::guard(function () use ($event) {
            $span = $this->pull($event->request);
            $host = (string) (parse_url($event->request->url(), PHP_URL_HOST) ?: 'unknown');

            if ($span !== null) {
                $span->setStatus(SpanStatus::Error, 'connection failed');
                $span->end();
            }

            // A host that never answered still gets a series here, which is
            // the worse half of the problem: an attacker-supplied hostname
            // needs no cooperation from the host to create one.
            [$record, $label] = $this->telemetry()->classifyHttpHost($host);

            if ($record) {
                $this->telemetry()
                    ->counter('http.client.connection_failures', 'Outgoing HTTP connection failures')
                    ->inc(1, ['server.address' => $label]);
            }
        });
    }

    private function pull(object $request): ?Span
    {
        $key = $this->keyFor($request);
        $span = $this->inFlight[$key] ?? null;
        unset($this->inFlight[$key]);

        return $span;
    }

    /**
     * Key on the PSR request, which the framework DOES preserve.
     *
     * Laravel does not hand the same `Illuminate\Http\Client\Request` wrapper
     * to every event — the connection-failure path builds a fresh one
     * (`new Request($e->getRequest())` in
     * PendingRequest::marshalTransportException) — so keying on the wrapper
     * missed on every failure, and the span was never ended. Because it stayed
     * on the tracer stack, every later span in the request was parented under
     * the call that had already failed. Both wrappers wrap the SAME PSR
     * instance, so that is the identity to use, and it stays exact for
     * concurrent pools where guessing by method/host/path could not.
     *
     * It is not preserved on every path, and the fallbacks that would paper
     * over that were removed because each one could close the WRONG span:
     *
     *  - Guzzle's streaming handler clones an HTTP/1.1 request to add
     *    `Connection: close`, so a failure on `withOptions(['stream' => true])`
     *    carries the clone. Any cloning middleware does the same.
     *  - A `beforeSending` callback returning a replacement request breaks the
     *    EXCEPTION path only; success still closes normally, because
     *    ResponseReceived carries the stored original wrapper.
     *  - A redirect emits RequestSending per hop but one ResponseReceived, so
     *    the earlier hops never match.
     *
     * Those leave their span open until flushRequestState() — which the Octane
     * and NativePHP request hooks and the non-sync job start call — rather than
     * closing a span with someone else's outcome. A missing span is much the
     * lesser evil; a span with the wrong duration and status is a lie that
     * reads as data.
     */
    private function keyFor(object $request): int
    {
        if ($request instanceof Request) {
            $psr = FailSafe::guard(fn (): object => $request->toPsrRequest());

            if (is_object($psr)) {
                return spl_object_id($psr);
            }
        }

        return spl_object_id($request);
    }

    public function flushRequestState(): void
    {
        // Dropping the map is not enough on its own: the spans stay on the
        // tracer's context stack, and the shutdown path ends every span still
        // open as an error that lasted until the process died. A healthy call
        // that merely followed a redirect would publish a FAILED client span
        // with a duration stretching to the end of the request — exactly the
        // lie the comment above says to avoid. Discarded properly instead.
        foreach ($this->inFlight as $span) {
            FailSafe::guard(fn () => $this->telemetry()->tracer()->discardSpan($span));
        }

        $this->inFlight = [];
    }
}
