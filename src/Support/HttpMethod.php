<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

/**
 * An HTTP method as a BOUNDED value.
 *
 * The method on a request is whatever the caller put on the request line —
 * nothing restricts it to a real verb, and a server span is started for
 * unmatched requests too. Used directly as a metric label that is a dimension
 * anyone can grow from outside the app, without authenticating and without
 * hitting a route.
 *
 * semconv answers this: a method the instrumentation does not know is reported
 * as `_OTHER`, and the original travels alongside as
 * `http.request.method_original`, so nothing is lost to the reader of a trace.
 *
 * The known list is configurable, because the nine semconv names are not every
 * real method — WebDAV alone adds PROPFIND, MKCOL and REPORT. An app that
 * serves those adds them to `telemetry.instrument.known_http_methods` and gets
 * its breakdown back.
 *
 * @see https://opentelemetry.io/docs/specs/semconv/http/http-spans/
 */
final class HttpMethod
{
    public const OTHER = '_OTHER';

    /** The methods semconv names. */
    public const SEMCONV = ['CONNECT', 'DELETE', 'GET', 'HEAD', 'OPTIONS', 'PATCH', 'POST', 'PUT', 'TRACE'];

    /**
     * The value for `http.request.method`.
     */
    public static function normalize(string $method): string
    {
        return self::isKnown($method) ? strtoupper($method) : self::OTHER;
    }

    /**
     * The value for `http.request.method_original`, or null when the method
     * needs no explaining — the attribute is only meaningful when the
     * normalized one hid something.
     */
    public static function original(string $method): ?string
    {
        return self::isKnown($method) ? null : $method;
    }

    private static function isKnown(string $method): bool
    {
        $configured = config('telemetry.instrument.known_http_methods');

        $known = is_array($configured) && $configured !== []
            ? array_map(static fn (mixed $m): string => strtoupper(Cast::string($m)), $configured)
            : self::SEMCONV;

        return in_array(strtoupper($method), $known, true);
    }
}
