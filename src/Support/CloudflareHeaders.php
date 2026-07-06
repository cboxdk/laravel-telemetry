<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

use Illuminate\Http\Request;

/**
 * `client.geo.*` from Cloudflare's edge headers — free country-level geo on
 * every plan (CF-IPCountry) with no MaxMind database to ship or update.
 *
 * These headers only exist on the server request, never in the browser, so
 * they work for both the server page view and the browser ingest endpoint
 * (which sees the visitor's own CF-IPCountry on the ingest request itself).
 *
 * SECURITY: CF-* headers are attacker-spoofable if the origin is reachable
 * around Cloudflare — anyone can send `CF-IPCountry: DK`. They are only
 * trusted here when the request arrived through a trusted proxy, i.e. the
 * immediate connection (REMOTE_ADDR) is your own ingress. Configure Laravel's
 * TrustProxies with THAT hop: the Cloudflare ranges when CF connects to the
 * app directly, or your load balancer in a `CF -> LB -> app` chain (the peer
 * the app sees is the LB, not Cloudflare — so you trust the LB, not the CF
 * ranges). Without trusted proxies this is a safe no-op rather than a
 * spoofing hole.
 *
 * Trusting the immediate hop is the same model as X-Forwarded-For: it proves
 * the request came through your infra, not that Cloudflare set the header, so
 * the edge must be the only ingress (the LB rejects non-CF traffic or strips
 * inbound CF-*). For a topology-independent guarantee, use Authenticated
 * Origin Pulls or verify a CF-set secret in a resolveClientGeoUsing() hook.
 */
final class CloudflareHeaders
{
    /**
     * @return array<string, scalar|null>
     */
    public static function geo(Request $request): array
    {
        if (! $request->isFromTrustedProxy()) {
            return [];
        }

        $country = $request->headers->get('CF-IPCountry');

        // CF sends XX (unknown) and T1 (Tor exit) as non-ISO sentinels — not
        // real countries, so they must not pollute a country facet.
        if ($country === null || $country === '' || $country === 'XX' || $country === 'T1') {
            return [];
        }

        // Region/city arrive only on Enterprise + a Managed Transform; on
        // every other plan they are simply absent and filtered out here.
        return array_filter([
            'client.geo.country' => strtoupper($country),
            'client.geo.region' => $request->headers->get('CF-Region'),
            'client.geo.city' => $request->headers->get('CF-IPCity'),
        ], static fn ($v): bool => $v !== null && $v !== '');
    }
}
