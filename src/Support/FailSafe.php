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
 *
 * The default handler is `report()` — the same function Laravel's
 * `rescue()` calls — so failures reach the application's own exception
 * handler, and through it Sentry, Bugsnag, the configured log channels
 * and any `dontReport` rules. Nothing is hidden; it is routed exactly
 * where the application already routes its errors.
 *
 * `rescue()` itself is not used, for two reasons that are specific to
 * this package rather than stylistic:
 *
 *  - This package SUBSCRIBES to `report()`, turning reports into
 *    exception records. A plain try/catch/report would therefore
 *    recurse: guard → report → subscriber → guard → report. The latch
 *    below stops that, and `rescue()` has nothing like it.
 *  - `rescue()` reports every occurrence. See below.
 *
 * And throttled, which matters as much as the swallowing. The guards sit
 * on paths that run per query, per cache operation, per Redis command —
 * so a backend that is down does not fail once, it fails tens of
 * thousands of times a minute. Reporting each one turns a degraded
 * dashboard into a log volume incident, and the log pipeline is usually
 * shared with the application whose availability this class exists to
 * protect. One report per distinct failure per minute is enough to know;
 * everything after that is noise with a bill attached.
 */
final class FailSafe
{
    /** Seconds between reports of the same failure in one process. */
    private const REPEAT_AFTER_SECONDS = 60;

    /**
     * Distinct failures tracked before the map resets. Reached only by
     * something pathological — a failure whose message varies per call —
     * and resetting is better than growing in a worker that lives for
     * days.
     */
    private const MAX_TRACKED = 256;

    private static ?Closure $handler = null;

    private static bool $handling = false;

    /** @var array<string, int> failure key → unix time last reported */
    private static array $lastReported = [];

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

        // A new handler has seen nothing yet. Without this a test, or an
        // application swapping the handler at runtime, would silently
        // receive nothing for a minute.
        self::$lastReported = [];
    }

    /**
     * Forget what has been reported.
     *
     * Deliberately NOT wired into the per-request reset: under Octane
     * that would mean one report per request, which is the storm the
     * throttle exists to prevent. The window is time-based and manages
     * itself. This is for a test, or for an operator who has just fixed
     * the backend and wants to see the next failure immediately.
     */
    public static function flush(): void
    {
        self::$lastReported = [];
    }

    /**
     * Once per distinct failure per minute.
     *
     * Keyed by where it was thrown and what it was, not by message: a
     * message often carries the key, the host or the id that varied,
     * and keying on it would defeat the throttle exactly when the
     * failure is high-volume.
     */
    private static function shouldReport(Throwable $e): bool
    {
        $key = $e::class.'@'.$e->getFile().':'.$e->getLine();
        $now = time();
        $last = self::$lastReported[$key] ?? null;

        if ($last !== null && $now - $last < self::REPEAT_AFTER_SECONDS) {
            return false;
        }

        if (count(self::$lastReported) >= self::MAX_TRACKED) {
            self::$lastReported = [];
        }

        self::$lastReported[$key] = $now;

        return true;
    }

    private static function handle(Throwable $e): void
    {
        // Re-entrancy latch: the default handler is report(), and telemetry
        // itself subscribes to report(). A guarded path that fails *while*
        // a telemetry failure is already being reported (e.g. the user
        // lookup with the database down) would otherwise recurse without
        // bound: guard → report → subscriber → guard → report → …
        if (self::$handling) {
            return;
        }

        if (! self::shouldReport($e)) {
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
