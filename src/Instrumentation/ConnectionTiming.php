<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\SpanStatus;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Records how long it took to open a connection to a backing service.
 *
 * Laravel's query and command events fire only once a connection is already
 * up, so the handshake — DNS, TCP, TLS, auth — is invisible to them. It is
 * also where a healthy-looking app spends its worst seconds: a database that
 * answers every query in a millisecond still hangs for thirty if the connect
 * blocks. This records that segment as its own span, so a waterfall shows the
 * wait instead of an unexplained gap before the first query.
 *
 * The span is NOT a detail span. A connect happens at most a handful of times
 * per request, and it is exactly what you want kept when a trace is trimmed
 * for being slow.
 */
final class ConnectionTiming
{
    public function __construct(private readonly Container $container) {}

    /**
     * Time a connect, record it, and hand back whatever the connect returned.
     *
     * The callable's exception is never swallowed — a failed connect must
     * reach the app exactly as it would without telemetry — but it IS
     * recorded first, because a connect that throws after twenty seconds is
     * the single most useful span in the trace.
     *
     * @template TConnection
     *
     * @param  callable(): TConnection  $connect
     * @param  array<string, string|int>  $attributes
     * @return TConnection
     */
    public function measure(string $spanName, string $system, string $connection, array $attributes, callable $connect): mixed
    {
        $start = hrtime(true);

        try {
            $result = $connect();
        } catch (Throwable $e) {
            $this->record($spanName, $system, $connection, $attributes, $this->elapsedMs($start), $e);

            throw $e;
        }

        $this->record($spanName, $system, $connection, $attributes, $this->elapsedMs($start), null);

        return $result;
    }

    /**
     * @param  array<string, string|int>  $attributes
     */
    private function record(string $spanName, string $system, string $connection, array $attributes, float $durationMs, ?Throwable $error): void
    {
        FailSafe::guard(function () use ($spanName, $system, $connection, $attributes, $durationMs, $error): void {
            $telemetry = $this->container->make(TelemetryManager::class);

            $telemetry
                ->histogram(
                    'db.client.connection.create_time',
                    buckets: [0.001, 0.0025, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10],
                    description: 'Time taken to open a connection to a backing service',
                    unit: 's',
                )
                ->record($durationMs / 1000, [
                    'db.system.name' => $system,
                    'laravel.db.connection' => $connection,
                    'outcome' => $error === null ? 'ok' : 'error',
                ]);

            if ($telemetry->currentSpan()?->sampled !== true) {
                return;
            }

            $span = $telemetry->tracer()->recordSpan(
                $spanName,
                max(0.0, $durationMs),
                array_merge($attributes, array_filter([
                    'db.system.name' => $system,
                    'laravel.db.connection' => $connection,
                    'error.type' => $error === null ? null : $error::class,
                ], static fn (mixed $value): bool => $value !== null)),
                SpanKind::Client,
            );

            if ($error !== null) {
                $span->setStatus(SpanStatus::Error, $error->getMessage());
            }
        });
    }

    private function elapsedMs(float|int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000;
    }
}
