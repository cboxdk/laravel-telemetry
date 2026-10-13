<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Http;

use Cbox\Telemetry\Support\AnalyticsIdentity;
use Cbox\Telemetry\Support\Cast;
use Illuminate\Foundation\Vite;

/**
 * Renders the @telemetryBrowser output: the traceparent meta (so the
 * browser roots on the server trace) plus the <script> tag for the
 * vendored @cboxdk/telemetry-browser bundle (see BrowserSdk), configured
 * through the data-* attributes its standalone build initialises from.
 * Empty when the ingest is disabled.
 */
final class BrowserSnippet
{
    /**
     * `ingest.spans.browser` toggles and the SDK switch each one drives.
     * All on unless set false; the SDK treats only "0" as off.
     */
    private const SWITCHES = [
        'fetch' => 'data-fetch',
        'xhr' => 'data-xhr',
        'errors' => 'data-errors',
        'vitals' => 'data-vitals',
        'navigation' => 'data-navigation',
        'frameworks' => 'data-frameworks',
        'server_timing' => 'data-server-timing',
    ];

    public static function render(): string
    {
        if (! config('telemetry.enabled')) {
            return '';
        }

        $config = Cast::stringKeyedArray(config('telemetry.ingest.spans', []));

        if (! Cast::flag($config['enabled'] ?? null)) {
            return '';
        }

        $browser = Cast::stringKeyedArray($config['browser'] ?? []);

        $traceparent = app('telemetry')->traceparent();
        $meta = is_string($traceparent) ? '<meta name="traceparent" content="'.e($traceparent).'">' : '';

        $asset = url(Cast::string($config['asset_path'] ?? null, 'telemetry/browser.js'));
        // The version busts the asset's day-long cache when the bundle changes.
        $asset .= (str_contains($asset, '?') ? '&' : '?').'v='.BrowserSdk::VERSION;

        $attributes = [
            'nonce' => self::nonce(),
            'data-endpoint' => url(Cast::string($config['path'] ?? null, 'telemetry/spans')),
        ];

        foreach (self::SWITCHES as $key => $attribute) {
            $attributes[$attribute] = Cast::flag($browser[$key] ?? null, true) ? '1' : '0';
        }

        $attributes['data-sample'] = Cast::string($browser['sample'] ?? null, '1.0');

        // The identity the backend stamps on its own spans, so a browser
        // span filters by the same service / environment / release (and
        // source maps line up with the release they were uploaded for).
        $attributes['data-service'] = Cast::string(config('telemetry.service.name'));
        $attributes['data-environment'] = Cast::string(config('telemetry.service.environment'));
        $attributes['data-release'] = Cast::string(config('telemetry.service.version'));

        if (config('telemetry.analytics.enabled', false)) {
            $attributes['data-analytics'] = '1';
            $attributes['data-session'] = self::sessionId();
        }

        if (Cast::flag($browser['debug'] ?? null)) {
            $attributes['data-debug'] = '1';
        }

        $html = '<script src="'.e($asset).'" defer';

        foreach ($attributes as $name => $value) {
            if ($value !== '') {
                $html .= ' '.$name.'="'.e($value).'"';
            }
        }

        // The header the server puts its trace id in: the SDK reads it off
        // Inertia responses to tell a continued trace from a new one. Sent
        // even when empty, which tells the SDK the header is off.
        $html .= ' data-trace-header="'.e(Cast::string(config('telemetry.traces.response_header'))).'"';

        return $meta.$html.'></script>';
    }

    /**
     * The request's CSP nonce, when the app set one with Laravel's
     * `Vite::useCspNonce()`: the same nonce its own script tags carry.
     */
    private static function nonce(): string
    {
        if (! class_exists(Vite::class)) {
            return '';
        }

        return Cast::string(app(Vite::class)->cspNonce());
    }

    /**
     * When analytics is on, propagate the shared session.id to the browser
     * so its RUM spans carry the SAME visit key as the server span (the
     * value is deterministic for this request, so it matches). Without it
     * the SDK falls back to its own per-tab session id.
     */
    private static function sessionId(): string
    {
        $request = request();

        return app('telemetry')->resolveSessionId($request)
            ?? AnalyticsIdentity::cookielessSession(
                $request,
                Cast::string(config('telemetry.analytics.session.salt')) ?: Cast::string(config('app.key')),
            );
    }
}
