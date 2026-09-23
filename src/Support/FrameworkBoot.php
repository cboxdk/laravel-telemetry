<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

/**
 * The framework boot a request paid for — at most once per process.
 *
 * LARAVEL_START is defined once per PROCESS, not per request. Under FPM
 * the two are the same thing. A long-lived runtime that defines it
 * (NativePHP's persistent interpreter, a custom worker) boots once and
 * serves every later request from the booted app, so measuring from
 * LARAVEL_START there reports the worker's age as "bootstrap". Only the
 * first request a process serves waited for the boot.
 *
 * Octane defines no LARAVEL_START, so it records no bootstrap at all.
 */
final class FrameworkBoot
{
    /** Anything longer is not a boot — a clock jump, or a worker's age. */
    private const MAX_MS = 60_000;

    private static bool $claimed = false;

    private static ?float $startedAt = null;

    /**
     * How long the framework took to boot for this request, in ms — the
     * first time it is asked in the process. Null on every later call,
     * when no boot start is known, and for implausible values.
     *
     * Every request must claim, including ones that record nothing:
     * whichever request the process serves first consumed the boot.
     */
    public static function claim(?float $now = null): ?float
    {
        if (self::$claimed) {
            return null;
        }

        self::$claimed = true;

        $startedAt = self::$startedAt
            ?? (defined('LARAVEL_START') ? Cast::float(constant('LARAVEL_START')) : 0.0);

        if ($startedAt <= 0) {
            return null;
        }

        $ms = (($now ?? microtime(true)) - $startedAt) * 1000;

        return $ms > 0 && $ms < self::MAX_MS ? $ms : null;
    }

    /**
     * @internal for tests — forget the claim, and optionally pin the boot
     *           start in place of LARAVEL_START (a constant a test cannot
     *           define without leaking it into every later test)
     */
    public static function flush(?float $startedAt = null): void
    {
        self::$claimed = false;
        self::$startedAt = $startedAt;
    }
}
