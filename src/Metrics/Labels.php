<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Metrics;

/**
 * Canonical label encoding shared by every metric store.
 *
 * Labels are sorted by key before encoding so {a,b} and {b,a} always hit
 * the same series.
 */
final class Labels
{
    /**
     * @param  array<string, scalar|null>  $labels
     */
    public static function encode(array $labels): string
    {
        if ($labels === []) {
            return '{}';
        }

        $normalized = [];

        foreach ($labels as $key => $value) {
            $normalized[$key] = $value === null ? '' : (string) $value;
        }

        ksort($normalized);

        return json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, string>
     */
    public static function decode(string $encoded): array
    {
        /** @var array<string, string> */
        return json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
    }
}
