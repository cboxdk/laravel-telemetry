<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Http\Middleware;

use Cbox\Telemetry\Support\Cast;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorizes the scrape endpoints — an admin route, since metric names and
 * label values can leak internals. Same convention as Horizon/Telescope/
 * Pulse: open in local/testing, closed everywhere else unless explicitly
 * configured. Three independent ways in, any one is sufficient:
 *
 *  1. `TELEMETRY_ALLOWED_IPS` — the requester's IP matches the allowlist
 *     (single IPs or CIDR ranges).
 *  2. `TELEMETRY_PROMETHEUS_TOKEN` — a bearer token, checked with
 *     hash_equals(). Prometheus's own scrape_config supports
 *     `authorization.credentials` natively, so a scraper that can't be
 *     IP-restricted can still authenticate.
 *  3. `app()->environment('local', 'testing')`.
 *
 * Swap in your own middleware via the endpoint config for anything more
 * bespoke (SSO, mTLS, …).
 */
final class AllowIps
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $allowed */
        $allowed = config('telemetry.prometheus.allowed_ips', []);

        if ($allowed !== []) {
            if (! IpUtils::checkIp((string) $request->ip(), $allowed)) {
                abort(403);
            }

            return $next($request);
        }

        if (app()->environment('local', 'testing')) {
            return $next($request);
        }

        $token = Cast::string(config('telemetry.prometheus.token'));

        if ($token !== '' && hash_equals($token, (string) $request->bearerToken())) {
            return $next($request);
        }

        abort(403, 'The telemetry metrics endpoint is closed outside local/testing. Set TELEMETRY_ALLOWED_IPS or TELEMETRY_PROMETHEUS_TOKEN.');
    }
}
