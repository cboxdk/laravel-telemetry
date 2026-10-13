<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

use Closure;

/**
 * What the package's own per-operation hooks cost the trace they ran in.
 *
 * "Zero cost when disabled" is a promise the package can check; the cost
 * when ENABLED is one it should be able to show. The tracer attaches the
 * total to the root span as `telemetry.overhead_ms`, so a slow request can
 * be asked "how much of this was us?" instead of guessed at.
 *
 * Measured: the hot per-operation listeners (queries, cache, model
 * events, views) and the request middleware's own work before and after
 * the application runs. Not measured: export, which happens after the
 * response is sent, and the framework's event dispatch around a listener.
 * The number is therefore a floor, not a full accounting — honest about
 * being one.
 *
 * Nesting counts once: a measured hook that triggers another (a query
 * listener whose span end flushes a buffer) is timed by the outermost.
 */
final class Overhead
{
    private static int $nanoseconds = 0;

    private static int $depth = 0;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function measure(Closure $callback): mixed
    {
        if (self::$depth++ > 0) {
            try {
                return $callback();
            } finally {
                self::$depth--;
            }
        }

        $start = hrtime(true);

        try {
            return $callback();
        } finally {
            self::$nanoseconds += hrtime(true) - $start;
            self::$depth--;
        }
    }

    /**
     * The accumulated time in milliseconds, and start over.
     */
    public static function takeMs(): float
    {
        $ms = self::$nanoseconds / 1_000_000;
        self::$nanoseconds = 0;

        return $ms;
    }

    public static function reset(): void
    {
        self::$nanoseconds = 0;
    }
}
