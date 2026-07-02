<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

/**
 * A W3C `traceparent` header value.
 *
 * Carries the trace id AND the parent span id, so continued spans become
 * children of the caller — never detached roots.
 *
 * @see https://www.w3.org/TR/trace-context/
 */
final readonly class TraceParent
{
    public function __construct(
        public string $traceId,
        public string $spanId,
        public bool $sampled = true,
    ) {}

    public static function parse(?string $header): ?self
    {
        if ($header === null) {
            return null;
        }

        $parts = explode('-', trim($header));

        if (count($parts) < 4) {
            return null;
        }

        [$version, $traceId, $spanId, $flags] = $parts;

        if ($version === 'ff' || preg_match('/^[0-9a-f]{2}$/', $version) !== 1) {
            return null;
        }

        if (! Ids::isValidTraceId($traceId) || ! Ids::isValidSpanId($spanId)) {
            return null;
        }

        if (preg_match('/^[0-9a-f]{2}$/', $flags) !== 1) {
            return null;
        }

        return new self(
            traceId: $traceId,
            spanId: $spanId,
            sampled: (hexdec($flags) & 0x01) === 1,
        );
    }

    public function toString(): string
    {
        return sprintf('00-%s-%s-%02x', $this->traceId, $this->spanId, $this->sampled ? 1 : 0);
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
