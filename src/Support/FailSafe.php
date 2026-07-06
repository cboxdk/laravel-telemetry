<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

use Closure;
use Throwable;

/**
 * Telemetry must never throw into the application.
 *
 * Every capture and export path runs through guard(). Failures are handed
 * to a configurable handler (default: Laravel's report()) and swallowed.
 */
final class FailSafe
{
    private static ?Closure $handler = null;

    private static bool $handling = false;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T|null
     */
    public static function guard(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            self::handle($e);

            return null;
        }
    }

    public static function handleExceptionsUsing(?Closure $handler): void
    {
        self::$handler = $handler;
    }

    private static function handle(Throwable $e): void
    {
        // Re-entrancy latch: the default handler is report(), and telemetry
        // itself subscribes to report(). A guarded path that fails *while*
        // a telemetry failure is already being reported (e.g. the enduser
        // lookup with the database down) would otherwise recurse without
        // bound: guard → report → subscriber → guard → report → …
        if (self::$handling) {
            return;
        }

        self::$handling = true;

        try {
            if (self::$handler !== null) {
                (self::$handler)($e);

                return;
            }

            if (function_exists('report')) {
                report($e);
            }
        } catch (Throwable) {
            // Swallow — telemetry failures must never cascade.
        } finally {
            self::$handling = false;
        }
    }
}
