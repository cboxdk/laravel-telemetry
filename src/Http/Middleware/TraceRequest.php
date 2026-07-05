<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Http\Middleware;

use Cbox\Telemetry\Support\AnalyticsIdentity;
use Cbox\Telemetry\Support\Cast;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\Support\GeoResolver;
use Cbox\Telemetry\Support\ResourceUsage;
use Cbox\Telemetry\Support\UserAgentParser;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\SpanStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Automatic HTTP server instrumentation.
 *
 * Starts a server span (continuing an incoming W3C traceparent when
 * configured), names it "METHOD /route/{pattern}" once routing has
 * resolved, records the request duration histogram, and flushes the
 * span buffer in terminate() — after the response has been sent.
 */
final class TraceRequest
{
    private const SPAN_KEY = 'cbox.telemetry.span';

    private const USAGE_KEY = 'cbox.telemetry.usage';

    /** Memory-peak buckets: 4 MB … 1 GB. */
    private const MEMORY_BUCKETS = [4194304, 8388608, 16777216, 33554432, 67108864, 134217728, 268435456, 536870912, 1073741824];

    /**
     * Never captured, even when explicitly allowlisted — credentials and
     * session material don't belong in telemetry.
     */
    private const SENSITIVE_HEADERS = [
        'authorization', 'proxy-authorization', 'cookie', 'set-cookie',
        'x-api-key', 'x-csrf-token', 'x-xsrf-token', 'php-auth-user', 'php-auth-pw', 'php-auth-digest',
    ];

    /** Query parameters whose values are redacted in url.query. */
    private const SENSITIVE_QUERY_PARAMS = ['token', 'api_key', 'apikey', 'key', 'secret', 'password', 'signature', 'auth', 'code', 'state'];

    public function __construct(private readonly TelemetryManager $telemetry) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->telemetry->enabled()) {
            return $next($request);
        }

        FailSafe::guard(function () use ($request) {
            if (config('telemetry.traces.continue_incoming')) {
                $this->telemetry->continueTrace(
                    $request->headers->get('traceparent'),
                    trustSampling: (bool) config('telemetry.traces.trust_incoming_sampling', true),
                );
            }

            $span = $this->telemetry->tracer()->startSpan(
                $request->method().' '.$request->path(),
                SpanKind::Server,
                array_filter([
                    'http.request.method' => $request->method(),
                    'url.path' => '/'.ltrim($request->path(), '/'),
                    'url.scheme' => $request->getScheme(),
                    'url.query' => $this->redactedQuery($request),
                    // The domain — apps routinely serve many subdomains or
                    // wildcards, and traces must be filterable by which one.
                    'server.address' => $request->getHost(),
                    'server.port' => $request->getPort(),
                    'client.address' => $request->ip(),
                    'user_agent.original' => $request->userAgent(),
                    'network.protocol.name' => 'http',
                    'network.protocol.version' => $this->protocolVersion($request),
                ], static fn ($value) => $value !== null && $value !== ''),
            );

            $request->attributes->set(self::SPAN_KEY, $span);

            $this->telemetry->publishTraceContext();

            // The framework-boot phase, visible in the waterfall — from
            // LARAVEL_START (public/index.php) until this middleware ran.
            if (defined('LARAVEL_START')) {
                $bootstrapMs = microtime(true) * 1000 - LARAVEL_START * 1000;

                if ($bootstrapMs > 0 && $bootstrapMs < 60_000) {
                    $this->telemetry->tracer()->recordSpan('laravel.bootstrap', $bootstrapMs);
                    $span->setAttribute('laravel.bootstrap_ms', round($bootstrapMs, 2));
                }
            }

            if (config('telemetry.instrument.resources', true)) {
                $request->attributes->set(self::USAGE_KEY, ResourceUsage::start());
            }
        });

        $response = $next($request);

        // Expose the trace id to the caller — the support-case reference
        // ("quote id X to support") and the debugging entry point.
        // Publicly cacheable responses are skipped: a cached copy would
        // replay one stale trace id to every subsequent visitor (CDNs,
        // static page caches), which defeats the header's purpose.
        FailSafe::guard(function () use ($response) {
            $header = config('telemetry.traces.response_header', 'X-Trace-Id');

            if (! is_string($header) || $header === '' || ($traceId = $this->telemetry->traceId()) === null) {
                return;
            }

            if ($response->headers->hasCacheControlDirective('public')
                || $response->headers->hasCacheControlDirective('s-maxage')) {
                return;
            }

            $response->headers->set($header, $traceId);
        });

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $span = $request->attributes->get(self::SPAN_KEY);

        if (! $span instanceof Span) {
            return;
        }

        FailSafe::guard(function () use ($request, $response, $span) {
            $template = $this->routePattern($request);

            // The logical route: an instrumentation can override the
            // literal route template for catch-all frameworks (a CMS's
            // "/{segments?}" identifies nothing) via resolveRouteUsing().
            // The override MUST be bounded — it becomes a metric label.
            $route = $this->telemetry->resolveRoute($request, $response) ?? $template;

            // Naming precedence: an explicit updateName() during the
            // request wins; then the app's nameRequestsUsing() resolver;
            // then "METHOD <logical route>".
            if (! $span->hasCustomName()) {
                $span->updateName($this->telemetry->resolveRequestName($request, $response)
                    ?? $request->method().' '.$route);
            }

            $span->setAttributes([
                'http.route' => $route,
                'http.response.status_code' => $response->getStatusCode(),
            ]);

            // Preserve the literal Laravel route template when overridden —
            // the raw pattern is still useful for debugging.
            if ($route !== $template) {
                $span->setAttribute('http.route.template', $template);
            }

            // Attribute the request to the authenticated user (resolved by
            // now) — enables per-user trace filtering. Id only, never PII.
            // Multi-guard apps (users/admins/resellers) are disambiguated:
            // enduser.type carries the model, enduser.guard the guard that
            // authenticated (Auth::shouldUse() from the route's auth
            // middleware is reflected here), so admin #7 and user #7 are
            // never the same identity.
            if (config('telemetry.instrument.user', true)) {
                if (($user = $request->user()) !== null) {
                    $guard = $this->authGuardName();

                    $span->setAttributes(array_filter([
                        'enduser.id' => Cast::string($user->getAuthIdentifier()),
                        'enduser.type' => Str::snake(class_basename($user)),
                        'enduser.guard' => $guard,
                    ]));
                    $span->setAttributes($this->telemetry->resolveUserAttributes($user, $guard));
                } elseif (($remembered = $this->telemetry->rememberedAuthenticatedUser()) !== null) {
                    // The login POST (user resolves after span start) and
                    // logout requests (guard empty by terminate) — the
                    // Login/Logout events remembered who it was.
                    $span->setAttributes(array_filter([
                        'enduser.id' => $remembered['id'],
                        'enduser.type' => $remembered['type'],
                        'enduser.guard' => $remembered['guard'],
                    ]));
                }
            }

            // Session dimension: the driver and a HASH of the id — never
            // the id itself, it is an authentication credential. The hash
            // is stable across the visit, so a whole user journey is one
            // TraceQL query: { span.session.hash = "..." }.
            if (config('telemetry.instrument.session', true) && $request->hasSession()) {
                $span->setAttributes([
                    'session.driver' => Cast::string(config('session.driver'), 'unknown'),
                    'session.hash' => substr(hash('sha256', $request->session()->getId()), 0, 16),
                ]);
            }

            // Analytics keystone (opt-in, default off): a shared, cross-request
            // session.id so a whole visit — not just one trace — can be
            // analysed, plus optional client.geo.*. Both are hook-overridable
            // (Cloudflare headers, a cookie, your own logic); the built-in
            // session.id is a cookieless, daily-rotating salted hash. Strictly
            // additive — nothing here runs, or is stamped, when analytics is
            // off, so existing telemetry is bit-for-bit unchanged.
            if (config('telemetry.analytics.enabled', false)) {
                $sessionId = $this->analyticsSessionId($request);
                $enrichment = [...$this->analyticsGeo($request), ...$this->analyticsUserAgent($request)];

                $span->setAttribute('session.id', $sessionId);
                $span->setAttributes($enrichment);

                // The unsampled analytics page-view: an EVENT (OTLP log), not
                // a span, so it survives trace sampling — a page view must
                // never be undercounted. Carries session.id + trace id as the
                // bridge to the (maybe-sampled-away) waterfall. Only for
                // top-level document loads (GET, HTML, non-AJAX) — the
                // canonical count that works even without JS.
                if (config('telemetry.analytics.page_views', true) && $this->isPageView($request, $response)) {
                    $this->emitPageView($request, $response, $sessionId, $enrichment);
                }
            }

            // App-defined root-span enrichment with the final response in
            // hand (Telemetry::enrichRequestsUsing) — status-dependent
            // attributes work here.
            $span->setAttributes($this->telemetry->resolveRequestEnrichment($request, $response));

            // Body sizes (OTel semconv). Response size is skipped for
            // streamed/binary responses where content isn't a string.
            $requestSize = $request->headers->get('Content-Length');
            $span->setAttribute('http.request.body.size', $requestSize !== null ? (int) $requestSize : strlen((string) $request->getContent()));

            $responseSize = $response->headers->get('Content-Length');

            if ($responseSize === null && ! $response instanceof StreamedResponse && ! $response instanceof BinaryFileResponse) {
                $content = $response->getContent();
                $responseSize = is_string($content) ? strlen($content) : null;
            }

            if ($responseSize !== null) {
                $span->setAttribute('http.response.body.size', (int) $responseSize);
            }

            // Allowlisted headers (OTel http.request.header.* /
            // http.response.header.*). Credentials and session material
            // are denylisted and never captured.
            $this->captureHeaders($span, 'http.request.header.', $request->headers, config('telemetry.instrument.request_headers', []));
            $this->captureHeaders($span, 'http.response.header.', $response->headers, config('telemetry.instrument.response_headers', []));

            if ($response->getStatusCode() >= 500) {
                $span->setStatus(SpanStatus::Error);
            } elseif ($span->status() === SpanStatus::Unset) {
                $span->setStatus(SpanStatus::Ok);
            }

            $labels = [
                // App-defined bounded dimensions (plan, team, …) via
                // Telemetry::labelRequestsUsing(); core labels win.
                ...$this->telemetry->resolveRequestLabels($request),
                'http.request.method' => $request->method(),
                'http.route' => $route,
                'http.response.status_code' => (string) $response->getStatusCode(),
            ];

            // Domain as a metric dimension. The ROUTE's domain pattern
            // ("{tenant}.app.example") wins over the concrete host, so
            // wildcard-tenant apps keep bounded cardinality while
            // multi-domain apps can still tell their domains apart.
            if (config('telemetry.instrument.host_label', true)) {
                $routeObject = $request->route();
                $domainPattern = is_object($routeObject) && method_exists($routeObject, 'getDomain') ? $routeObject->getDomain() : null;

                $labels['server.address'] = is_string($domainPattern) && $domainPattern !== '' ? $domainPattern : $request->getHost();
            }

            // Peak memory and CPU delta for THIS request — Nightwatch-style
            // resource attribution, per route and per custom dimension.
            $usage = $request->attributes->get(self::USAGE_KEY);
            $measured = $usage instanceof ResourceUsage ? $usage->measure() : null;

            if ($measured !== null) {
                $span->setAttributes(array_filter([
                    'php.memory.peak_bytes' => $measured['memoryPeakBytes'],
                    'php.cpu.time_ms' => $measured['cpuTimeMs'],
                    // Real OS footprint via cboxdk/system-metrics, when installed:
                    'process.memory.rss_peak_bytes' => $measured['rssPeakBytes'],
                    'process.cpu.utilization' => $measured['cpuUtilization'],
                ], static fn ($value) => $value !== null));
            }

            $span->end();

            $this->telemetry
                ->histogram('http.server.request.duration', description: 'HTTP server request duration', unit: 'ms')
                ->record($span->durationMs(), $labels);

            if ($measured !== null) {
                $this->telemetry
                    ->histogram('http.server.memory.peak', buckets: self::MEMORY_BUCKETS, description: 'Peak memory per request', unit: 'By')
                    ->record((float) $measured['memoryPeakBytes'], $labels);

                $this->telemetry
                    ->histogram('http.server.cpu.time', description: 'CPU time per request', unit: 'ms')
                    ->record($measured['cpuTimeMs'], $labels);
            }
        });

        $this->telemetry->flush();
        $this->telemetry->resetContext();
    }

    /**
     * @param  mixed  $allowlist
     */
    private function captureHeaders(Span $span, string $prefix, HeaderBag $headers, $allowlist): void
    {
        if (! is_array($allowlist)) {
            return;
        }

        foreach ($allowlist as $name) {
            if (! is_string($name)) {
                continue;
            }

            $name = strtolower($name);

            if (in_array($name, self::SENSITIVE_HEADERS, true) || ! $headers->has($name)) {
                continue;
            }

            $values = array_filter($headers->all($name), is_string(...));

            if ($values !== []) {
                $span->setAttribute($prefix.str_replace('-', '_', $name), implode(', ', $values));
            }
        }
    }

    /**
     * The query string with common secret parameters redacted — tokens,
     * signatures and OAuth material never leave the app.
     */
    private function redactedQuery(Request $request): ?string
    {
        $query = $request->server->get('QUERY_STRING');

        if (! is_string($query) || $query === '') {
            return null;
        }

        $pattern = '/(^|&)('.implode('|', self::SENSITIVE_QUERY_PARAMS).')=[^&]*/i';

        return (string) preg_replace($pattern, '$1$2=REDACTED', $query);
    }

    /**
     * The guard that authenticated this request. The framework's auth
     * middleware calls Auth::shouldUse($guard), so the auth manager's
     * default driver reflects the ACTUAL guard — 'admin' on an
     * auth:admin route, not the config default.
     */
    private function authGuardName(): ?string
    {
        $guard = FailSafe::guard(static function () {
            $auth = app('auth');

            return method_exists($auth, 'getDefaultDriver') ? $auth->getDefaultDriver() : null;
        });

        return is_string($guard) && $guard !== '' ? $guard : null;
    }

    /**
     * A top-level document load worth counting as a page view: a GET that
     * returns HTML and isn't an AJAX/fetch call. Assets, API/JSON and XHR are
     * excluded — the browser SDK counts client-side navigations separately.
     */
    private function isPageView(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || $request->ajax()) {
            return false;
        }

        return str_contains((string) $response->headers->get('Content-Type', ''), 'text/html');
    }

    /**
     * Emit the unsampled `analytics.page_view` event (an OTLP log record, so
     * it survives trace sampling). Flat, one-row-per-view shape with a
     * `telemetry.stream` marker so an OTel Collector can route it to
     * ClickHouse without any app change.
     *
     * @param  array<string, scalar|null>  $geo
     */
    private function emitPageView(Request $request, Response $response, string $sessionId, array $geo): void
    {
        $attributes = array_filter([
            'telemetry.stream' => 'analytics',
            'analytics.source' => 'server',
            'analytics.event' => 'page_view',
            'session.id' => $sessionId,
            'url.path' => $this->requestPath($request),
            'http.route' => $this->routePattern($request),
            'http.request.method' => $request->getMethod(),
            'http.response.status_code' => $response->getStatusCode(),
            'user_agent.original' => $request->userAgent(),
            'http.request.header.referer' => $request->headers->get('referer'),
        ], static fn ($v) => $v !== null);

        if (($user = $request->user()) !== null) {
            $attributes['enduser.id'] = Cast::string($user->getAuthIdentifier());
        }

        /** @var array<string, scalar|null> $attributes */
        $this->telemetry->event('analytics.page_view', [...$attributes, ...$geo]);
    }

    /**
     * The normalized request path ("/" for the root).
     */
    private function requestPath(Request $request): string
    {
        $path = trim($request->path(), '/');

        return $path === '' ? '/' : '/'.$path;
    }

    /**
     * `client.geo.*` for the request: a registered hook wins (e.g. Cloudflare
     * edge headers); otherwise the optional built-in MaxMind resolver when
     * `analytics.geo` is enabled (a no-op without the geoip2/geoip2 package).
     *
     * @return array<string, scalar|null>
     */
    private function analyticsGeo(Request $request): array
    {
        $geo = $this->telemetry->resolveClientGeo($request);

        if ($geo !== [] || ! config('telemetry.analytics.geo.enabled', false)) {
            return $geo;
        }

        return app(GeoResolver::class)->resolve($request->ip());
    }

    /**
     * Low-cardinality UA family dimensions, when `analytics.user_agent` is on.
     *
     * @return array<string, string>
     */
    private function analyticsUserAgent(Request $request): array
    {
        if (! config('telemetry.analytics.user_agent', false)) {
            return [];
        }

        return UserAgentParser::parse($request->userAgent());
    }

    /**
     * The analytics session.id: a registered hook wins; otherwise the
     * built-in cookieless, daily-rotating salted default.
     */
    private function analyticsSessionId(Request $request): string
    {
        $resolved = $this->telemetry->resolveSessionId($request);

        if ($resolved !== null) {
            return $resolved;
        }

        $salt = Cast::string(config('telemetry.analytics.session.salt')) ?: Cast::string(config('app.key'));

        return AnalyticsIdentity::cookielessSession($request, $salt);
    }

    /**
     * "HTTP/2" → "2", "HTTP/1.1" → "1.1" (OTel network.protocol.version).
     */
    private function protocolVersion(Request $request): ?string
    {
        $protocol = $request->getProtocolVersion();

        if (! is_string($protocol) || ! str_starts_with($protocol, 'HTTP/')) {
            return null;
        }

        return substr($protocol, 5);
    }

    /**
     * The low-cardinality route pattern ("/users/{user}"), falling back
     * to a constant when no route matched.
     */
    private function routePattern(Request $request): string
    {
        $route = $request->route();

        if (is_object($route) && method_exists($route, 'uri')) {
            return '/'.ltrim($route->uri(), '/');
        }

        return '/{unmatched}';
    }
}
