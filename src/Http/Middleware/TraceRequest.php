<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Http\Middleware;

use Cbox\Telemetry\Http\RequestPhases;
use Cbox\Telemetry\Native\NativeProfiler;
use Cbox\Telemetry\Native\NativeReporter;
use Cbox\Telemetry\Native\NativeUnit;
use Cbox\Telemetry\Support\AnalyticsIdentity;
use Cbox\Telemetry\Support\Baggage;
use Cbox\Telemetry\Support\CampaignAttribution;
use Cbox\Telemetry\Support\Cast;
use Cbox\Telemetry\Support\ClientGeo;
use Cbox\Telemetry\Support\CpuProfiler;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\Support\FrameworkBoot;
use Cbox\Telemetry\Support\HttpMethod;
use Cbox\Telemetry\Support\Redactor;
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

    private const PROFILE_KEY = 'cbox.telemetry.profile';

    private const NATIVE_KEY = 'cbox.telemetry.native';

    private const IGNORED_KEY = 'cbox.telemetry.ignored';

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

    /**
     * Query parameter names are blanked at CAPTURE time, by their DECODED
     * name — the half a pattern cannot do, since seeing that `%74oken` and
     * `token%5B%5D` are the parameter `token` means decoding it.
     *
     * The lists themselves live on Redactor, which applies the same test at
     * export to every attribute value on the way out. One definition, so the
     * two passes cannot drift apart.
     *
     * @see Redactor::parameterIsCredential()
     */
    public function __construct(
        private readonly TelemetryManager $telemetry,
        private readonly NativeProfiler $native,
        private readonly RequestPhases $phases,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->telemetry->enabled()) {
            return $next($request);
        }

        // Claimed before anything can return early: the process's first
        // request consumed the boot whether or not it records it.
        $bootstrapMs = FrameworkBoot::claim();

        // An ignored path (instrument.http_ignore_paths / Telemetry::
        // ignorePaths) — decided once, here, before anything is started. No
        // server span, no incoming trace continued, no native unit adopted,
        // no X-Trace-Id; and the tracer is suppressed rather than merely left
        // without a root, because the HTTP client, mail and notification
        // instrumentations open spans with or without a parent — each would
        // otherwise become a trace root of its own. Exception records still
        // get written (the reportable hook doesn't go through here), just
        // without a trace id to a trace that doesn't exist.
        if (FailSafe::guard(fn (): bool => $this->telemetry->ignoresPath($request->path())) === true) {
            $request->attributes->set(self::IGNORED_KEY, true);
            $this->telemetry->tracer()->suppress();

            return $next($request);
        }

        FailSafe::guard(function () use ($request, $bootstrapMs) {
            if (config('telemetry.traces.continue_incoming')) {
                $this->telemetry->continueTrace(
                    $request->headers->get('traceparent'),
                    trustSampling: (bool) config('telemetry.traces.trust_incoming_sampling', true),
                );
            }

            // W3C baggage: inherit the CALLER's Telemetry::context()
            // dimensions (team, tenant, plan, …), not just the trace id —
            // gated on the same trust boundary as continuing the trace
            // itself, since baggage is caller-supplied, unvalidated data.
            if (config('telemetry.instrument.baggage', true) && config('telemetry.traces.continue_incoming')) {
                $baggage = Baggage::parse($request->headers->get('baggage'));

                if ($baggage !== []) {
                    $this->telemetry->context($baggage);
                }
            }

            $span = $this->telemetry->tracer()->startSpan(
                HttpMethod::forSpanName($request->method()).' '.$request->path(),
                SpanKind::Server,
                array_filter([
                    'http.request.method' => HttpMethod::normalize($request->method()),
                    // Only when normalizing hid something, which is exactly
                    // when the reader needs it.
                    'http.request.method_original' => HttpMethod::original($request->method()),
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
            if ($bootstrapMs !== null) {
                $this->telemetry->tracer()->recordSpan('laravel.bootstrap', $bootstrapMs);
                $span->setAttribute('laravel.bootstrap_ms', round($bootstrapMs, 2));
            }

            $this->phases->start($span, $this->telemetry->tracer());

            if (config('telemetry.instrument.resources', true)) {
                $request->attributes->set(self::USAGE_KEY, ResourceUsage::start());
            }

            // The native unit of work: CPU profile, connection/cURL timing,
            // runtime counters, and the trace context a crash record is
            // correlated by. Under cbox_telemetry.auto it is already open and
            // this call adopts it — which is how the profile comes to cover
            // the bootstrap a middleware could never see.
            $unit = $this->native->begin('http', $span);

            if ($unit !== null) {
                $request->attributes->set(self::NATIVE_KEY, $unit);
            }

            // ext-excimer is the fallback, not a second opinion: two samplers
            // running at once mostly measure each other. The test is whether
            // the NATIVE sampler is running, not whether this call got a
            // unit — a unit opens even where profiling is unavailable, and
            // reading a handle as "profiling is covered" silenced excimer on
            // every host with the extension but no usable timer.
            if (! $this->native->profiles() && config('telemetry.instrument.profiling', true) && $span->sampled) {
                $request->attributes->set(self::PROFILE_KEY, CpuProfiler::start(
                    Cast::float(config('telemetry.profiling.period'), 0.001),
                ));
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
        if ($request->attributes->get(self::IGNORED_KEY) === true) {
            // Nothing of the request's own to finish — only what it wrote
            // (exception records, metrics from other instrumentation) to
            // ship, and the suppression to lift before the next request on
            // a long-lived worker.
            $this->telemetry->flush();
            $this->telemetry->resetContext();

            return;
        }

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
            // Livewire's update endpoint gets the same treatment built in:
            // "POST /livewire/update" identifies nothing either, so it is
            // named after the component(s) the request actually touched.
            $route = $this->telemetry->resolveRoute($request, $response)
                ?? $this->livewireRoute($request, $span)
                ?? $template;

            // Naming precedence: an explicit updateName() during the
            // request wins; then the app's nameRequestsUsing() resolver;
            // then "METHOD <logical route>".
            if (! $span->hasCustomName()) {
                $span->updateName($this->telemetry->resolveRequestName($request, $response)
                    ?? HttpMethod::forSpanName($request->method()).' '.$route);
            }

            $span->setAttributes([
                'http.route' => $route,
                'http.response.status_code' => $response->getStatusCode(),
            ]);

            $span->setAttributes($this->routeAction($request));

            // Preserve the literal Laravel route template when overridden —
            // the raw pattern is still useful for debugging.
            if ($route !== $template) {
                $span->setAttribute('http.route.template', $template);
            }

            // Attribute the request to the authenticated user (resolved by
            // now) — enables per-user trace filtering. Id only, never PII.
            // Multi-guard apps (users/admins/resellers) are disambiguated:
            // user.type carries the model, user.guard the guard that
            // authenticated (Auth::shouldUse() from the route's auth
            // middleware is reflected here), so admin #7 and user #7 are
            // never the same identity.
            if (config('telemetry.instrument.user', true)) {
                if (($user = $request->user()) !== null) {
                    $guard = $this->authGuardName();

                    $span->setAttributes(array_filter([
                        'user.id' => Cast::string($user->getAuthIdentifier()),
                        'user.type' => Str::snake(class_basename($user)),
                        'user.guard' => $guard,
                    ]));
                    $span->setAttributes($this->telemetry->resolveUserAttributes($user, $guard));
                } elseif (($remembered = $this->telemetry->rememberedAuthenticatedUser()) !== null) {
                    // The login POST (user resolves after span start) and
                    // logout requests (guard empty by terminate) — the
                    // Login/Logout events remembered who it was.
                    $span->setAttributes(array_filter([
                        'user.id' => $remembered['id'],
                        'user.type' => $remembered['type'],
                        'user.guard' => $remembered['guard'],
                    ]));
                }
            }

            // Inertia awareness: a request span attribute plus a counter
            // for version-mismatch reloads — pure response inspection,
            // no dependency on inertiajs/inertia-laravel being installed.
            if (config('telemetry.instrument.inertia', true)) {
                $this->annotateInertia($request, $response, $span);
            }

            // Rate limiting: a 429 response is the driver-agnostic signal
            // (Laravel's RateLimiter fires no event) — works for
            // ThrottleRequests AND any custom limiter that returns 429.
            if (config('telemetry.instrument.rate_limiting', true) && $response->getStatusCode() === 429) {
                $this->telemetry->counter('rate_limit.exceeded', 'Requests rejected for exceeding a rate limit')
                    ->inc(1, ['limiter' => $this->rateLimiterName($request)]);
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
            // analysed, plus optional geo.*. Both are hook-overridable
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
                'http.request.method' => HttpMethod::normalize($request->method()),
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

                $labels['server.address'] = is_string($domainPattern) && $domainPattern !== ''
                    ? $domainPattern
                    : $this->boundedHost($request);
            }

            // Peak memory and CPU delta for THIS request — per-route,
            // per-custom-dimension resource attribution.
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

            // Before end(): the operation aggregates and counters are
            // attributes of THIS span, and a span that has ended has no
            // duration to decide anything by either.
            $unit = $request->attributes->get(self::NATIVE_KEY);

            if ($unit instanceof NativeUnit) {
                // The decision in force NOW: a per-route Sample::never()
                // drops every span of this trace, and a profile with no
                // trace to line it up against is not worth materialising.
                $result = $unit->finish($this->telemetry->tracer()->currentlySampled($span));

                if ($result !== null) {
                    NativeReporter::report($this->telemetry, $result, $span, ['http.route' => $route]);
                }
            }

            // The last phase closes here, and with it the answer to how
            // long the CLIENT waited — this terminate() runs after the
            // response went out, behind the session save and every
            // defer() callback.
            $respondedMs = $this->phases->finish();

            $span->end();

            $profile = $request->attributes->get(self::PROFILE_KEY);

            if ($profile instanceof CpuProfiler) {
                $this->reportProfile($profile, $span->durationMs(), $labels);
            }

            // Seconds. This name is a STABLE OpenTelemetry metric whose unit
            // is fixed to seconds; emitting it in milliseconds meant every
            // stock dashboard and alert looking for
            // http_server_request_duration_seconds_bucket found nothing, and a
            // collector fed this alongside any other OTel SDK saw the same
            // metric name arrive with two different units.
            //
            // The ladder is FINER than semconv's advisory one below 5ms, which
            // would have put every sub-5ms request in one bucket — coarser than
            // the millisecond ladder it replaces. The unit is fixed by the
            // spec; the buckets are only advisory, so there is no reason to
            // lose resolution to gain conformance.
            //
            // Measured to the response being sent, not to the span's end:
            // work after the send (defer(), terminable middleware) isn't
            // latency anyone waited for, and counting it made a request
            // that answered in 80 ms and deferred 2 s of work read as a
            // 2 s request. The span still covers it, as laravel.terminate.
            $this->telemetry
                ->histogram('http.server.request.duration', buckets: [0.0005, 0.001, 0.0025, 0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 10], description: 'HTTP server request duration', unit: 's')
                ->record(($respondedMs ?? $span->durationMs()) / 1000, $labels);

            if ($measured !== null) {
                $this->telemetry
                    ->histogram('http.server.memory.peak', buckets: self::MEMORY_BUCKETS, description: 'Peak memory per request', unit: 'By')
                    ->record((float) $measured['memoryPeakBytes'], $labels);

                $this->telemetry
                    ->histogram('http.server.cpu.time', description: 'CPU time per request', unit: 'ms')
                    ->record($measured['cpuTimeMs'], $labels);
            }
        });

        // Whatever happened above — including a throw the guard swallowed
        // before the unit was finished — the native unit closes here. A
        // no-op once it has been finished; the difference on an Octane or
        // NativePHP worker is the one-unit-at-a-time rule staying usable.
        $native = $request->attributes->get(self::NATIVE_KEY);

        if ($native instanceof NativeUnit) {
            FailSafe::guard(static fn () => $native->discard());
        }

        // Same reason: a throw before finish() leaves the phases pointing
        // at this request's span, where a later one would record into it.
        $this->phases->flushRequestState();

        $this->telemetry->flush();
        $this->telemetry->resetContext();
    }

    /**
     * The concrete host, but only when something has vouched for it.
     *
     * `$request->getHost()` is the client's `Host:` header. Symfony validates
     * it only when the app configured trusted-host patterns, and Laravel ships
     * with none — so on a default install this is an attacker-controlled string
     * going straight onto three histograms as a LABEL. A loop with an
     * incrementing Host mints a permanent series per value, and no store here
     * has a TTL or a cardinality cap. No route needs to match: an unrouted
     * request still gets labelled.
     *
     * So: trust it when trusted-host patterns exist (Symfony has already
     * thrown on anything else by the time we are called), otherwise keep it
     * only when it IS the app's own host — which is the single-domain case,
     * where the label is a constant anyway and nothing is lost. Everything
     * else collapses to one bucket.
     *
     * A multi-domain app that wants its domains apart should configure
     * TrustHosts, or register the routes with a domain pattern; both are
     * bounded by the app rather than by the caller.
     */
    private function boundedHost(Request $request): string
    {
        if (Request::getTrustedHosts() !== []) {
            return $request->getHost();
        }

        $host = $request->getHost();
        $appHost = Cast::string(parse_url(Cast::string(config('app.url'), ''), PHP_URL_HOST), '');

        return $appHost !== '' && strcasecmp($host, $appHost) === 0 ? $host : 'other';
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

        return self::redactQueryString($query);
    }

    /**
     * The same treatment for a URL captured whole.
     *
     * The referer is a URL a browser sends us, and an OAuth callback that the
     * user navigated away from puts its authorization code in exactly that
     * header. Only the query part is rewritten; the rest is left alone so the
     * attribute still reads as the URL it was.
     */
    private static function redactedUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }

        $mark = strpos($url, '?');

        if ($mark === false) {
            return $url;
        }

        // A fragment is not part of the query and must survive intact.
        $query = substr($url, $mark + 1);
        $hash = strpos($query, '#');
        $fragment = $hash === false ? '' : substr($query, $hash);

        if ($hash !== false) {
            $query = substr($query, 0, $hash);
        }

        return substr($url, 0, $mark + 1).self::redactQueryString($query).$fragment;
    }

    /**
     * Blank the value of every credential parameter in a query string.
     */
    private static function redactQueryString(string $query): string
    {
        // Taken apart rather than pattern-matched: the separators are kept
        // verbatim so the attribute still reads like the query it was, and
        // only the value of a sensitive parameter is replaced. A pair with no
        // `=` is passed through untouched.
        //
        // Split on `&` ALONE. PHP's arg_separator.input is `&`, so a `;` is an
        // ordinary character inside a value — treating it as a separator cut
        // `code=4/0A;rest` in half and published the tail, which is worse than
        // what a single pattern used to do.
        $parts = explode('&', $query);
        $redacted = [];

        foreach ($parts as $part) {
            $equals = strpos($part, '=');

            if ($equals === false) {
                $redacted[] = $part;

                continue;
            }

            $name = substr($part, 0, $equals);

            // Spelled like the Redactor's default replacement so the two
            // passes agree — the export patterns run over this string too, and
            // re-matching a value they already blanked must be a no-op rather
            // than a second, differently-spelled substitution.
            $redacted[] = self::parameterIsSensitive($name) ? $name.'=[REDACTED]' : $part;
        }

        return implode('&', $redacted);
    }

    private static function parameterIsSensitive(string $name): bool
    {
        // A query string is a real query, so the ambiguous names — `key`,
        // `code`, `state`, `auth` — mean what they say here.
        return Redactor::parameterIsCredential($name, allowAmbiguous: true);
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
     * `inertia.request` when the client's own XHR navigation sent the
     * `X-Inertia` header (the initial full-page load never does). A
     * matching `X-Inertia-Location` on the response means Inertia's own
     * middleware detected an asset-version mismatch and is forcing a
     * full page reload — worth a counter, since a spike right after a
     * deploy is expected but a sustained rate means the client-side
     * asset version never settles.
     */
    private function annotateInertia(Request $request, Response $response, Span $span): void
    {
        if ($request->headers->get('X-Inertia') !== 'true') {
            return;
        }

        $span->setAttribute('inertia.request', true);

        if ($response->headers->has('X-Inertia-Location')) {
            $span->setAttribute('inertia.version_mismatch', true);
            $this->telemetry->counter('inertia.version_mismatches', 'Inertia asset-version mismatches forcing a full page reload')->inc();
        }
    }

    /**
     * The `throttle:<name>` route middleware's limiter name — the one
     * label RateLimiter::for() callbacks are actually registered under.
     * An inline spec (`throttle:60,1`, bare `throttle`) has no name to
     * give, so it's bucketed as "default" rather than the raw numbers
     * (unbounded per-route tuning would otherwise leak into the label).
     */
    private function rateLimiterName(Request $request): string
    {
        $route = $request->route();
        $middleware = is_object($route) && method_exists($route, 'gatherMiddleware') ? $route->gatherMiddleware() : [];

        foreach ($middleware as $entry) {
            if (! is_string($entry) || ! str_starts_with($entry, 'throttle')) {
                continue;
            }

            $params = str_contains($entry, ':') ? substr($entry, strpos($entry, ':') + 1) : '';

            if ($params === '') {
                return 'default';
            }

            $name = explode(',', $params)[0];

            return is_numeric($name) ? 'default' : $name;
        }

        return 'unknown';
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
            'http.request.method' => HttpMethod::normalize($request->getMethod()),
            'http.request.method_original' => HttpMethod::original($request->getMethod()),
            'http.response.status_code' => $response->getStatusCode(),
            'user_agent.original' => $request->userAgent(),
            'http.request.header.referer' => self::redactedUrl($request->headers->get('referer')),
        ], static fn ($v) => $v !== null);

        if (($user = $request->user()) !== null) {
            $attributes['user.id'] = Cast::string($user->getAuthIdentifier());
        }

        /** @var array<string, scalar|null> $attributes */
        $this->telemetry->event('analytics.page_view', [...$attributes, ...$geo, ...$this->utmAttributes($request)]);
    }

    /**
     * `analytics.utm.*` + a low-cardinality `analytics.click_id` from the
     * landing URL's query, when `analytics.utm` is on. See
     * {@see CampaignAttribution} for the exact keys and the click-id
     * allowlist. Off by default and strictly additive.
     *
     * @return array<string, string>
     */
    private function utmAttributes(Request $request): array
    {
        if (! config('telemetry.analytics.utm', false)) {
            return [];
        }

        return CampaignAttribution::fromQuery($request->query());
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
     * `geo.*` for the request: a registered hook wins, then the
     * built-in Cloudflare CF-IPCountry header (trusted-proxy gated), then the
     * optional MaxMind resolver — all when `analytics.geo` is enabled. See
     * {@see ClientGeo} for the shared precedence.
     *
     * @return array<string, scalar|null>
     */
    private function analyticsGeo(Request $request): array
    {
        return ClientGeo::resolve($request, $this->telemetry);
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
    /**
     * Which code answered, as OpenTelemetry names it.
     *
     * http.route says which URL pattern matched, which is not the same
     * question: two routes can share a controller, and one controller can
     * answer a dozen routes. Without this a trace shows the path and the
     * queries underneath it, and nothing about what ran in between.
     *
     * Laravel writes the action three ways. "Class@method" is the common
     * one; an invokable controller is the bare class, which is dispatched
     * through __invoke; a closure route has no class at all and reports
     * the literal string "Closure". A closure gets code.function alone —
     * inventing a namespace for it would be a lie a UI then groups by.
     *
     * Bounded by the route table, so it is safe on a span and would be
     * safe as a label; it is set here as an attribute because that is
     * where per-request detail belongs.
     *
     * @return array<string, string>
     */
    private function routeAction(Request $request): array
    {
        $route = $request->route();

        if (! is_object($route) || ! method_exists($route, 'getActionName')) {
            return [];
        }

        $action = $route->getActionName();

        if (! is_string($action) || $action === '') {
            return [];
        }

        if ($action === 'Closure') {
            return ['code.function' => 'Closure'];
        }

        if (str_contains($action, '@')) {
            [$class, $method] = explode('@', $action, 2);

            return ['code.namespace' => $class, 'code.function' => $method];
        }

        // An invokable controller: the action is the class, and Laravel
        // dispatches __invoke on it.
        return ['code.namespace' => $action, 'code.function' => '__invoke'];
    }

    private function routePattern(Request $request): string
    {
        $route = $request->route();

        if (is_object($route) && method_exists($route, 'uri')) {
            return '/'.ltrim($route->uri(), '/');
        }

        return '/{unmatched}';
    }

    /**
     * The logical route for Livewire's update endpoint: every component
     * update POSTs to the same URL, so the literal route identifies
     * nothing. LivewireInstrumentation collects the component names as
     * they hydrate/mount; a single-component request (the common case)
     * becomes "livewire:{component}", a batched one "livewire:batch" —
     * bounded either way, because component aliases are a fixed set and
     * batch compositions are not enumerated. The span carries the full
     * list in livewire.components regardless.
     */
    private function livewireRoute(Request $request, Span $span): ?string
    {
        // Mirrors LivewireInstrumentation::COMPONENTS_KEY — inlined so this
        // middleware never loads that class (its parent, Livewire's
        // ComponentHook, only exists when livewire/livewire is installed).
        $components = $request->attributes->get('telemetry.livewire.components');

        if (! is_array($components) || $components === [] || ! $this->isLivewireUpdateRoute($request)) {
            return null;
        }

        /** @var list<string> $components */
        $span->setAttribute('livewire.components', implode(',', $components));

        return count($components) === 1 ? 'livewire:'.$components[0] : 'livewire:batch';
    }

    private function isLivewireUpdateRoute(Request $request): bool
    {
        $route = $request->route();

        if (! is_object($route)) {
            return false;
        }

        if (method_exists($route, 'named') && $route->named('livewire.update')) {
            return true;
        }

        return method_exists($route, 'uri') && str_ends_with('/'.ltrim($route->uri(), '/'), '/livewire/update');
    }

    /**
     * @param  array<string, scalar|null>  $labels
     */
    private function reportProfile(CpuProfiler $profile, float $durationMs, array $labels): void
    {
        $top = $profile->stop(Cast::int(config('telemetry.profiling.top_functions'), 20));

        if ($top === null || $durationMs < Cast::float(config('telemetry.profiling.min_duration_ms'), 500.0)) {
            return;
        }

        $this->telemetry->event('profile.captured', [
            'http.route' => Cast::string($labels['http.route'] ?? null),
            'duration_ms' => round($durationMs, 2),
            'profile.source' => 'excimer',
            'profile.top_functions' => json_encode($top, JSON_UNESCAPED_SLASHES) ?: '[]',
        ]);
    }
}
