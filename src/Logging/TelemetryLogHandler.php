<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Logging;

use Cbox\Telemetry\Events\TelemetryEvent;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
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
 */
final class TelemetryLogHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly TelemetryManager $telemetry,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        FailSafe::guard(function () use ($record) {
            $span = $this->telemetry->currentSpan();

            $this->telemetry->recordEvent(new TelemetryEvent(
                name: $record->message,
                timeUnixNano: (int) ((float) $record->datetime->format('U.u') * 1e9),
                attributes: $this->telemetry->contextAttributes()
                    + ['log.channel' => $record->channel]
                    + $this->contextAttributes($record->context),
                traceId: $span->traceId ?? $this->telemetry->traceId(),
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
