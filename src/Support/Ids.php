<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

/**
 * W3C-compatible trace and span id generation.
 *
 * Deliberately not the OTel SDK: a trace id is 16 random bytes hex, a span
 * id is 8 random bytes hex. That's all there is to it.
 */
final class Ids
{
    public static function traceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function spanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    public static function isValidTraceId(string $id): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $id) === 1 && $id !== str_repeat('0', 32);
    }

    public static function isValidSpanId(string $id): bool
    {
        return preg_match('/^[0-9a-f]{16}$/', $id) === 1 && $id !== str_repeat('0', 16);
    }
}
