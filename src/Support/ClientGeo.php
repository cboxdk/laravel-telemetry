<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

use Cbox\Telemetry\TelemetryManager;
use Illuminate\Http\Request;

/**
 * Resolves `client.geo.*` for a request with one fixed precedence, so the
 * request middleware and the browser ingest endpoint agree on a single
 * source of truth:
 *
 *   1. a {@see TelemetryManager::resolveClientGeoUsing()} hook — always
 *      wins, unconditionally (your own logic overrides everything);
 *   2. Cloudflare's CF-IPCountry edge header — free, no database, but only
 *      when trusted ({@see CloudflareHeaders});
 *   3. an optional MaxMind database ({@see GeoResolver}).
 *
 * Tiers 2 and 3 are gated on `analytics.geo.enabled`; the hook is not.
 */
final class ClientGeo
{
    /**
     * @return array<string, scalar|null>
     */
    public static function resolve(Request $request, TelemetryManager $telemetry): array
    {
        $geo = $telemetry->resolveClientGeo($request);

        if ($geo !== [] || ! config('telemetry.analytics.geo.enabled', false)) {
            return $geo;
        }

        if (config('telemetry.analytics.geo.cloudflare', true)) {
            $cf = CloudflareHeaders::geo($request);

            if ($cf !== []) {
                return $cf;
            }
        }

        return app(GeoResolver::class)->resolve($request->ip());
    }
}
