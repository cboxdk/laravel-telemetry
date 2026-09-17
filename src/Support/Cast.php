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
     * A CONFIG flag, which is not the same thing as a bool.
     *
     * Laravel's `env()` converts `true`, `false`, `null` and `empty` to their
     * PHP values and leaves everything else a string — so `FEATURE=0` reaches
     * config as the string `"0"`, which `bool()` rejects as "not a bool" and
     * answers with the DEFAULT. Every such switch then did the opposite of
     * what the `.env` file said, silently and only for the numeric spelling.
     *
     * Use this for anything a user can write in `.env`; use `bool()` for a
     * value that is genuinely supposed to be a boolean already (a framework
     * interface typed `mixed`, an extension's status array).
     */
    public static function flag(mixed $value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }

        if (! is_string($value)) {
            return $default;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'on', 'yes' => true,
            '0', 'false', 'off', 'no', '' => false,
            default => $default,
        };
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
