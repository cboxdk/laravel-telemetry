<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

use Cbox\Telemetry\TelemetryManager;
use Illuminate\Http\Request;

/**
 * The built-in, privacy-first default for the analytics `session.id` when no
 * {@see TelemetryManager::resolveSessionUsing()} hook is
 * registered.
 *
 * Cookieless and unlinkable across days: a salted SHA-256 of the day + IP +
 * user agent + host (the "Fathom trick"). Because the day is part of the
 * input, the hash rotates every midnight — the raw IP is never a durable
 * grouping key and cannot be reversed. It gives a stable per-visitor-per-day
 * identity that is good enough for uniques / top pages / referrers on a
 * low-traffic LGTM stack; exact per-visit sessions want a cookie or a
 * Cloudflare-style id supplied through the hook.
 */
final class AnalyticsIdentity
{
    /**
     * Deterministic for a given (day, ip, ua, host, salt) — so the value the
     * browser snippet emits and the value the middleware stamps match within
     * the same request/day without any shared state.
     */
    public static function cookielessSession(Request $request, string $salt, ?string $day = null): string
    {
        $day ??= date('Y-m-d');

        $material = implode('|', [
            $day,
            (string) $request->ip(),
            (string) $request->userAgent(),
            (string) $request->getHost(),
            $salt,
        ]);

        return substr(hash('sha256', $material), 0, 32);
    }
}
