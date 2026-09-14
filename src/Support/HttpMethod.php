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
     * The value for `http.request.method_original`, or null when nothing was
     * hidden.
     *
     * Compares against what normalize() actually returns rather than asking
     * whether the method is known — `GeT` is known, and normalizing it to
     * `GET` still changes it, so the original belongs on the span.
     */
    public static function original(string $method): ?string
    {
        return self::normalize($method) === $method ? null : $method;
    }

    /**
     * The method portion of a span NAME.
     *
     * semconv is explicit that an unknown method must not reach the name
     * either: the name would otherwise be caller-controlled, and anything
     * deriving a dimension from span names inherits the same unbounded set.
     */
    public static function forSpanName(string $method): string
    {
        return self::isKnown($method) ? strtoupper($method) : 'HTTP';
    }

    private static function isKnown(string $method): bool
    {
        return in_array(strtoupper($method), self::known(), true);
    }

    /**
     * @return list<string>
     */
    private static function known(): array
    {
        $configured = config('telemetry.instrument.known_http_methods');

        // An explicitly empty array is an override, not an absence: an app
        // that wants every method bucketed is entitled to say so. Only a
        // missing or non-array value falls back to the defaults.
        if (! is_array($configured)) {
            return self::SEMCONV;
        }

        return array_values(array_map(strtoupper(...), Cast::stringList($configured)));
    }
}
