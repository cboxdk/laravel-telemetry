<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

/**
 * Campaign attribution from a landing URL's query string: the standard
 * `utm_*` parameters as `analytics.utm.*`, plus a low-cardinality
 * `analytics.click_id` that carries the NAME of the paid ad-network click-id
 * parameter present (gclid/msclkid/…), never its unbounded value.
 *
 * Kept in one place so the server page view (from the request) and the
 * browser ingest (from the client-sent landing URL) derive the exact same
 * keys — a downstream analytics UI reads them verbatim.
 *
 * Deliberately excludes `fbclid`: Meta appends it to organic clicks too, so
 * it is not a reliable paid-traffic signal and would only inflate the
 * click-id dimension.
 */
final class CampaignAttribution
{
    /** Cap on a captured utm value — mirrors the ingest MAX_VALUE bound. */
    private const MAX_VALUE = 1024;

    /**
     * The utm parameter → attribute-key suffix map, in schema order.
     *
     * @var array<string, string>
     */
    private const UTM_PARAMS = [
        'utm_source' => 'analytics.utm.source',
        'utm_medium' => 'analytics.utm.medium',
        'utm_campaign' => 'analytics.utm.campaign',
        'utm_content' => 'analytics.utm.content',
        'utm_term' => 'analytics.utm.term',
    ];

    /**
     * Reliably-paid ad-network click-id parameters, in precedence order —
     * the first one present wins. `fbclid` is intentionally absent.
     *
     * @var list<string>
     */
    private const CLICK_IDS = ['gclid', 'gbraid', 'wbraid', 'msclkid', 'dclid', 'ttclid', 'twclid', 'yclid'];

    /**
     * Resolve `analytics.utm.*` + `analytics.click_id` from a query bag
     * (`utm_source` => 'x', …). Only present, non-empty parameters produce a
     * key; utm values are lowercased, trimmed and length-capped; the click-id
     * key holds the parameter NAME, never its value.
     *
     * @param  array<array-key, mixed>  $query
     * @return array<string, string>
     */
    public static function fromQuery(array $query): array
    {
        $attributes = [];

        foreach (self::UTM_PARAMS as $param => $key) {
            $value = self::value($query[$param] ?? null);

            if ($value !== null) {
                $attributes[$key] = $value;
            }
        }

        foreach (self::CLICK_IDS as $param) {
            if (array_key_exists($param, $query) && self::value($query[$param]) !== null) {
                $attributes['analytics.click_id'] = $param;
                break;
            }
        }

        return $attributes;
    }

    /**
     * Resolve the attributes from a full URL's query string — the shape the
     * browser sends its landing URL in. A malformed or query-less URL yields
     * no attributes.
     *
     * @return array<string, string>
     */
    public static function fromUrl(?string $url): array
    {
        if ($url === null || $url === '') {
            return [];
        }

        $queryString = parse_url($url, PHP_URL_QUERY);

        if (! is_string($queryString) || $queryString === '') {
            return [];
        }

        parse_str($queryString, $query);

        return self::fromQuery($query);
    }

    /**
     * A normalized, capped scalar value, or null when absent/empty.
     */
    private static function value(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $value = mb_strtolower(trim($raw));

        return $value === '' ? null : mb_substr($value, 0, self::MAX_VALUE);
    }
}
