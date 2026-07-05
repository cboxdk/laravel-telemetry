<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

/**
 * Type-narrowing readers for `mixed` values whose real shape isn't
 * statically known (config/env values, framework interfaces typed
 * `mixed`, …). A value of the wrong shape degrades to the given default
 * rather than producing PHP's silent, often wrong scalar coercions
 * (`(int) 'abc'` === 0, `(string) ['x']` === 'Array').
 */
final class Cast
{
    public static function string(mixed $value, string $default = ''): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : $default;
    }

    public static function int(mixed $value, int $default = 0): int
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (int) $value : $default;
    }

    public static function float(mixed $value, float $default = 0.0): float
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (float) $value : $default;
    }

    public static function bool(mixed $value, bool $default = false): bool
    {
        return is_bool($value) ? $value : $default;
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $out[$key] = $item;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    /**
     * @return array<string, string>
     */
    public static function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && is_string($item)) {
                $out[$key] = $item;
            }
        }

        return $out;
    }

    /**
     * @return array<string, bool|float|int|string|null>
     */
    public static function scalarMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && (is_scalar($item) || $item === null)) {
                $out[$key] = $item;
            }
        }

        return $out;
    }
}
