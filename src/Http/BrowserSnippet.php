<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Http;

use Cbox\Telemetry\Support\AnalyticsIdentity;
use Cbox\Telemetry\Support\Cast;

/**
 * Renders the @telemetryBrowser output: the traceparent meta (so the
 * browser roots on the server trace) plus the RUM <script> tag configured
 * via data-* attributes. Empty when the ingest is disabled.
 */
final class BrowserSnippet
{
    public static function render(): string
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('telemetry.ingest.spans', []);

        if (! ($config['enabled'] ?? false)) {
            return '';
        }

        /** @var array<string, mixed> $browser */
        $browser = (array) ($config['browser'] ?? []);

        $traceparent = app('telemetry')->traceparent();
        $meta = is_string($traceparent) ? '<meta name="traceparent" content="'.e($traceparent).'">' : '';

        $asset = url(Cast::string($config['asset_path'] ?? null, 'telemetry/browser.js'));
        $endpoint = url(Cast::string($config['path'] ?? null, 'telemetry/spans'));

        $analytics = config('telemetry.analytics.enabled', false) ? ' data-analytics="1"' : '';

        return $meta.'<script src="'.e($asset).'" defer'
            .' data-endpoint="'.e($endpoint).'"'
            .' data-fetch="'.(($browser['fetch'] ?? true) ? '1' : '0').'"'
            .' data-errors="'.(($browser['errors'] ?? true) ? '1' : '0').'"'
            .' data-sample="'.e(Cast::string($browser['sample'] ?? null, '1.0')).'"'
            .$analytics
            .self::sessionAttribute().'></script>';
    }

    /**
     * When analytics is on, propagate the shared session.id to the browser
     * so its RUM spans carry the SAME visit key as the server span (the
     * value is deterministic for this request, so it matches). Empty
     * otherwise — the SDK falls back to its own per-tab session id.
     */
    private static function sessionAttribute(): string
    {
        if (! config('telemetry.analytics.enabled', false)) {
            return '';
        }

        $request = request();

        $sessionId = app('telemetry')->resolveSessionId($request)
            ?? AnalyticsIdentity::cookielessSession(
                $request,
                Cast::string(config('telemetry.analytics.session.salt')) ?: Cast::string(config('app.key')),
            );

        return ' data-session="'.e($sessionId).'"';
    }
}
