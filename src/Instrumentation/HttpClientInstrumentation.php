<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\SpanStatus;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;

/**
 * Outgoing HTTP instrumentation: a client span per Http-client request,
 * a duration histogram by peer host/method/status, and a connection-
 * failure counter. Attributes carry host + path — never the query string
 * (tokens live there).
 */
final class HttpClientInstrumentation
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

            $this->inFlight[spl_object_id($event->request)] = $this->telemetry()->tracer()->startSpan(
                $event->request->method().' '.$host,
                SpanKind::Client,
                [
                    'http.request.method' => $event->request->method(),
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
                $span->setStatus($event->response->status() >= 400 ? SpanStatus::Error : SpanStatus::Ok);
                $span->end();

                $this->telemetry()
                    ->histogram('http.client.request.duration', description: 'Outgoing HTTP request duration', unit: 'ms')
                    ->record($span->durationMs(), [
                        'http.request.method' => $event->request->method(),
                        'server.address' => (string) $span->attributes()['server.address'],
                        'http.response.status_code' => (string) $event->response->status(),
                    ]);
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

            $this->telemetry()
                ->counter('http.client.connection_failures', 'Outgoing HTTP connection failures')
                ->inc(1, ['server.address' => $host]);
        });
    }

    private function pull(object $request): ?Span
    {
        $key = spl_object_id($request);
        $span = $this->inFlight[$key] ?? null;
        unset($this->inFlight[$key]);

        return $span;
    }
}
