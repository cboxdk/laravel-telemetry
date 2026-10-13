<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

use Closure;

/**
 * Work the package does for itself through the application's own
 * services — reading directives from the app's cache, which on Laravel's
 * default `database` cache store is a query.
 *
 * Instrumented like the app's work, it would show up as the app's: an
 * extra `select … from cache` in every request's `db.query.count`, a
 * cache miss per request in the hit ratio. The query and cache listeners
 * skip anything run inside here.
 */
final class OwnWork
{
    private static int $depth = 0;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(Closure $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public static function active(): bool
    {
        return self::$depth > 0;
    }
}
