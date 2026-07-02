<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts scrape endpoints to the configured IPs (single or CIDR).
 * An empty allowlist allows everyone — lock it down in production or
 * swap in your own middleware via the endpoint config.
 */
final class AllowIps
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $allowed */
        $allowed = config('telemetry.prometheus.allowed_ips', []);

        if ($allowed !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowed)) {
            abort(403);
        }

        return $next($request);
    }
}
