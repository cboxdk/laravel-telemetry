<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

/**
 * The W3C `baggage` header: carries `Telemetry::context()` dimensions
 * (team, tenant, plan, …) across a service boundary, alongside
 * `traceparent` — so a downstream service inherits the SAME custom
 * dimensions the caller set, not just the trace id.
 *
 * Round-trips only the key=value pair; W3C's optional `;property=value`
 * metadata is dropped on parse (this package never wrote it either).
 * Keys/values are percent-encoded, same convention as
 * `OTEL_RESOURCE_ATTRIBUTES` elsewhere in this package.
 *
 * @see https://www.w3.org/TR/baggage/
 */
final class Baggage
{
    /** The spec's own limits — keeps the header itself well-behaved. */
    private const MAX_BYTES = 8192;

    private const MAX_MEMBERS = 180;

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    public static function encode(array $attributes): ?string
    {
        $pairs = [];
        $length = 0;

        foreach ($attributes as $key => $value) {
            if ($value === null || count($pairs) >= self::MAX_MEMBERS) {
                continue;
            }

            $pair = rawurlencode((string) $key).'='.rawurlencode((string) $value);
            $addedLength = strlen($pair) + ($pairs === [] ? 0 : 1); // +1 for the joining comma

            if ($length + $addedLength > self::MAX_BYTES) {
                break;
            }

            $pairs[] = $pair;
            $length += $addedLength;
        }

        return $pairs === [] ? null : implode(',', $pairs);
    }

    /**
     * @return array<string, string>
     */
    public static function parse(?string $header): array
    {
        if (! is_string($header) || $header === '') {
            return [];
        }

        $attributes = [];

        foreach (explode(',', $header) as $member) {
            // Drop any `;property=value` metadata — only key=value round-trips.
            $member = explode(';', trim($member), 2)[0];

            if (! str_contains($member, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $member, 2);
            $key = rawurldecode(trim($key));

            if ($key === '') {
                continue;
            }

            $attributes[$key] = rawurldecode(trim($value));

            if (count($attributes) >= self::MAX_MEMBERS) {
                break;
            }
        }

        return $attributes;
    }
}
