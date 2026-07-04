<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

/**
 * A small, dependency-free User-Agent parser: enough to segment analytics by
 * browser / OS / device family without every consumer parsing the raw string
 * itself, and without pulling in a heavy UA library. It deliberately extracts
 * only low-cardinality families (never versions), so it stays a safe metric-
 * and group-by dimension.
 *
 * For exhaustive detection, leave this off and parse `user_agent.original` at
 * query time, or supply your own values through a request-enrichment hook.
 */
final class UserAgentParser
{
    /**
     * @return array<string, string> subset of user_agent.name / os.name /
     *                               device.type (only the ones detected)
     */
    public static function parse(?string $ua): array
    {
        if ($ua === null || $ua === '') {
            return [];
        }

        return array_filter([
            'user_agent.name' => self::browser($ua),
            'os.name' => self::os($ua),
            'device.type' => self::device($ua),
        ], static fn (?string $v): bool => $v !== null && $v !== '');
    }

    private static function browser(string $ua): ?string
    {
        // Order matters: Edge/Opera/Samsung masquerade as Chrome; Chrome as
        // Safari. Bots first.
        return match (true) {
            self::isBot($ua) => 'Bot',
            str_contains($ua, 'Edg') => 'Edge',
            str_contains($ua, 'OPR') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox') || str_contains($ua, 'FxiOS') => 'Firefox',
            str_contains($ua, 'Chrome') || str_contains($ua, 'CriOS') => 'Chrome',
            str_contains($ua, 'Safari') => 'Safari',
            default => null,
        };
    }

    private static function os(string $ua): ?string
    {
        return match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') || str_contains($ua, 'iPod') => 'iOS',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Linux') => 'Linux',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            default => null,
        };
    }

    private static function device(string $ua): string
    {
        return match (true) {
            self::isBot($ua) => 'bot',
            str_contains($ua, 'iPad') || (str_contains($ua, 'Tablet') && ! str_contains($ua, 'Mobile')) => 'tablet',
            str_contains($ua, 'Mobi') || str_contains($ua, 'iPhone') || str_contains($ua, 'Android') => 'mobile',
            default => 'desktop',
        };
    }

    private static function isBot(string $ua): bool
    {
        return preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|headless|lighthouse|pingdom|monitor/i', $ua) === 1;
    }
}
