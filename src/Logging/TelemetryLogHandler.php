<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Logging;

use Cbox\Telemetry\Events\TelemetryEvent;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Closure;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Monolog handler behind the `telemetry` log channel.
 *
 * Every log record becomes an OTLP log record: Monolog level mapped to the
 * OTLP severity range, scalar context flattened to attributes, and — the
 * point of it all — correlated to the active trace, so logs appear on the
 * trace timeline in Tempo/Grafana.
 *
 * The manager is resolved lazily, per write, rather than captured at
 * construction: the log channel is often built and cached before a test
 * swaps in `Telemetry::fake()`, and a captured reference would keep pointing
 * at the original manager — so faked assertions would silently miss log
 * events. Resolving on each write always honours the current binding.
 */
final class TelemetryLogHandler extends AbstractProcessingHandler
{
    /** @var Closure(): TelemetryManager */
    private readonly Closure $resolveTelemetry;

    /**
     * @param  Closure(): TelemetryManager  $telemetry
     */
    public function __construct(
        Closure $telemetry,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        $this->resolveTelemetry = $telemetry;

        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        FailSafe::guard(function () use ($record) {
            $telemetry = ($this->resolveTelemetry)();

            // Point LOG_DEPRECATIONS_CHANNEL=telemetry (or include the
            // telemetry channel in its stack) and deprecations become
            // countable — the pre-upgrade checklist as a metric.
            if ($record->channel === 'deprecations') {
                $telemetry->counter('php.deprecations', 'Deprecation notices logged')->inc();
            }

            $span = $telemetry->currentSpan();

            $telemetry->recordEvent(new TelemetryEvent(
                name: $record->message,
                timeUnixNano: (int) ((float) $record->datetime->format('U.u') * 1e9),
                attributes: $telemetry->contextAttributes()
                    + ['log.channel' => $record->channel]
                    + $this->contextAttributes($record->context),
                traceId: $span->traceId ?? $telemetry->traceId(),
                spanId: $span?->spanId,
                severityNumber: $this->severityNumber($record->level),
                severityText: $record->level->getName(),
            ));
        });
    }

    /**
     * Map Monolog levels onto the OTLP severity ranges
     * (TRACE 1-4, DEBUG 5-8, INFO 9-12, WARN 13-16, ERROR 17-20, FATAL 21-24).
     */
    private function severityNumber(Level $level): int
    {
        return match ($level) {
            Level::Debug => 5,
            Level::Info => 9,
            Level::Notice => 10,
            Level::Warning => 13,
            Level::Error => 17,
            Level::Critical => 21,
            Level::Alert => 22,
            Level::Emergency => 23,
        };
    }

    /**
     * Flatten context to scalar attributes; non-scalars are JSON-encoded
     * so nothing is silently dropped.
     *
     * @param  array<mixed>  $context
     * @return array<string, scalar|null>
     */
    private function contextAttributes(array $context): array
    {
        $attributes = [];

        foreach ($context as $key => $value) {
            if ($value === null || is_scalar($value)) {
                $attributes["log.context.{$key}"] = $value;

                continue;
            }

            if ($value instanceof \Throwable) {
                $attributes['exception.type'] = $value::class;
                $attributes['exception.message'] = $value->getMessage();

                continue;
            }

            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

            $attributes["log.context.{$key}"] = $encoded === false ? get_debug_type($value) : $encoded;
        }

        return $attributes;
    }
}
