<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Exporters\Spool;

use Cbox\Telemetry\Support\Cast;

/**
 * Decodes a spooled line back into the entry shape the {@see Spool}
 * contract promises. A spool reads bytes someone else wrote — an older
 * package version, a truncated write, a hand-edited row — so anything
 * that does not decode to the shape is dropped rather than shipped.
 */
final class SpoolEntry
{
    /**
     * @return array{signal: string, payload: array<string, mixed>}|null
     */
    public static function decode(string $raw): ?array
    {
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return null;
        }

        $signal = $decoded['signal'] ?? null;

        if (! is_string($signal) || ! is_array($decoded['payload'] ?? null)) {
            return null;
        }

        return ['signal' => $signal, 'payload' => Cast::stringKeyedArray($decoded['payload'])];
    }
}
