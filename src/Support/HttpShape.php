<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

/**
 * The shape of an outgoing HTTP request: the call with every value taken
 * out — the HTTP twin of SqlShape.
 *
 * `GET /users/17` and `GET /users/42` are one endpoint called twice. A
 * loop that fetches a related resource per row looks exactly like that,
 * and grouping by the raw URL splits it into as many "different" calls as
 * there are rows. So path segments that look like ids become `{id}` and
 * query values are dropped (the parameter NAMES stay: `?expand=x` and
 * `?fields=y` ask different questions of the same path).
 *
 * What counts as an id: a number (`17`, `2024-01-31`), a UUID, a ULID, a
 * hex digest of 16+ characters, a Stripe-style prefixed token
 * (`cus_NffrFeUfNV2Hib`), a long token mixing letters and digits, and an
 * email address. Words stay words: `v2`, `oauth2` and `users` are names,
 * not values. A file extension is kept apart from its stem, so
 * `/orders/17.json` is `/orders/{id}.json`.
 *
 * A heuristic, not a router. It runs at most once per outgoing call — a
 * network round trip dwarfs it — and a shape that is occasionally too fine
 * costs a missed group, never a wrong one.
 */
final class HttpShape
{
    public const PLACEHOLDER = '{id}';

    /**
     * Query parameters that page through a collection. Compared lowercased
     * and without a bracket suffix, so `page[cursor]` and `Page` match.
     */
    private const PAGINATION_PARAMS = [
        'page', 'cursor', 'offset', 'skip', 'after', 'before', 'marker',
        'starting_after', 'ending_before', 'since_id', 'max_id',
        'page_token', 'pagetoken', 'next_page_token', 'nextpagetoken',
        'continuation', 'continuation_token', 'continuationtoken',
        'next', 'next_cursor', 'start_cursor', 'pageafter', 'pagebefore',
    ];

    /**
     * The low-cardinality template of a path: `/users/17/posts` →
     * `/users/{id}/posts`. What `url.template` means in semconv, guessed
     * from the values rather than read from a route table.
     */
    public static function template(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        $segments = explode('/', $path);

        foreach ($segments as $i => $segment) {
            if ($segment !== '') {
                $segments[$i] = self::segment($segment);
            }
        }

        $template = implode('/', $segments);

        return str_starts_with($template, '/') ? $template : '/'.$template;
    }

    /**
     * `GET api.example.com/users/{id}?expand&fields` — method, authority,
     * path template and the sorted query parameter NAMES.
     */
    public static function normalize(string $method, string $authority, string $path, string $query = ''): string
    {
        $shape = strtoupper($method).' '.strtolower($authority).self::template($path);
        $names = self::queryNames($query);

        return $names === [] ? $shape : $shape.'?'.implode('&', $names);
    }

    /**
     * A short, stable id for a shape — 16 hex chars, the same width as the
     * query shape hash, so a UI can group both the same way.
     */
    public static function hash(string $method, string $authority, string $path, string $query = ''): string
    {
        return hash('xxh3', self::normalize($method, $authority, $path, $query));
    }

    /**
     * Whether the call only reads. N+1 is a read pattern — a loop of POSTs
     * is a missing bulk endpoint, which the caller often cannot add, and
     * calling it N+1 would send someone looking for a batch read that does
     * not exist. Same line as SqlShape::isRead().
     */
    public static function isRead(string $method): bool
    {
        $method = strtoupper($method);

        return $method === 'GET' || $method === 'HEAD';
    }

    /**
     * Whether the call walks a collection a page at a time: a paging query
     * parameter (`?page=2`, `?cursor=…`, `?page[after]=…`, `?offset=…`) or
     * a `/page/2` path. One endpoint with a new value per call is what
     * paging IS; calling it N+1 asks for a batch read that cannot exist.
     */
    public static function isPagination(string $path, string $query = ''): bool
    {
        foreach (self::queryNames($query) as $name) {
            $bare = strtolower(explode('[', $name, 2)[0]);

            // page[number], page[after], … reduce to `page`.
            if (in_array($bare, self::PAGINATION_PARAMS, true)) {
                return true;
            }
        }

        return preg_match('~/page/\d+(?:/|$)~i', $path) === 1;
    }

    private static function segment(string $segment): string
    {
        $decoded = rawurldecode($segment);

        if (self::isId($decoded)) {
            return self::PLACEHOLDER;
        }

        // `17.json`: the stem is the value, the extension is part of the
        // endpoint.
        if (preg_match('/^(.+)(\.[A-Za-z][A-Za-z0-9]{0,4})$/', $decoded, $m) === 1 && self::isId($m[1])) {
            return self::PLACEHOLDER.$m[2];
        }

        return $segment;
    }

    private static function isId(string $segment): bool
    {
        return preg_match(
            '/^(?:'
            // 17, 2024-01-31, 1.5
            .'\d+(?:[-.:]\d+)*'
            // UUID
            .'|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'
            // ULID (Crockford base32; first char 0-7)
            .'|[0-7][0-9a-hjkmnp-tv-z]{25}'
            // hex digests: md5, sha1, sha256, Mongo ObjectId, …
            .'|[0-9a-f]{16,}'
            // prefixed tokens: cus_NffrFeUfNV2Hib, ch_3MmlLrLkdIwHu7ix
            // (case-sensitive: `user_settings` is a name, the uppercase or
            // digit is what makes the tail random)
            .'|(?-i:[a-z]{1,8}_(?=[a-z0-9]*[0-9A-Z])[A-Za-z0-9]{8,})'
            // a long token mixing letters and digits
            .'|(?=[a-z_-]*\d)(?=[0-9_-]*[a-z])[a-z0-9_-]{16,}'
            // an email address
            .'|[^@\/\s]+@[^@\/\s]+'
            .')$/iD',
            $segment,
        ) === 1;
    }

    /**
     * @return list<string> distinct parameter names, sorted
     */
    private static function queryNames(string $query): array
    {
        if ($query === '') {
            return [];
        }

        $names = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            $name = urldecode(explode('=', $pair, 2)[0]);

            if ($name !== '') {
                $names[$name] = true;
            }
        }

        $names = array_keys($names);
        sort($names);

        return array_map(strval(...), $names);
    }
}
