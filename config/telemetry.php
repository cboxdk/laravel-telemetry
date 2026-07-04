<?php

declare(strict_types=1);
use Cbox\Telemetry\Http\Middleware\AllowIps;

// Honor the OpenTelemetry-standard OTEL_EXPORTER_OTLP_HEADERS
// ("key1=val1,key2=val2") for interop. TELEMETRY_* values win over these.
$otelHeaders = [];
if (is_string($rawOtelHeaders = env('OTEL_EXPORTER_OTLP_HEADERS')) && $rawOtelHeaders !== '') {
    foreach (explode(',', $rawOtelHeaders) as $pair) {
        if (str_contains($pair, '=')) {
            [$hk, $hv] = explode('=', $pair, 2);

            if (($hk = trim($hk)) !== '') {
                $otelHeaders[$hk] = trim($hv);
            }
        }
    }
}

return [

    /*
    |--------------------------------------------------------------------------
    | Master Switch
    |--------------------------------------------------------------------------
    |
    | When disabled, every instrument resolves to a no-op, no event listeners
    | are registered, and no providers are booted. A disabled install adds no
    | measurable per-request overhead.
    |
    */

    'enabled' => env('TELEMETRY_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Service Resource
    |--------------------------------------------------------------------------
    |
    | Identifies this application on every exported signal, following the
    | OpenTelemetry resource semantic conventions.
    |
    */

    'service' => [
        'name' => env('TELEMETRY_SERVICE_NAME', env('OTEL_SERVICE_NAME', env('APP_NAME', 'laravel'))),
        'namespace' => env('TELEMETRY_SERVICE_NAMESPACE'),
        'version' => env('TELEMETRY_SERVICE_VERSION'),
        'environment' => env('TELEMETRY_SERVICE_ENVIRONMENT', env('APP_ENV', 'production')),

        // Deployment marker (git sha, release tag) — shows on every
        // signal so regressions map to deploys.
        'deployment' => env('TELEMETRY_SERVICE_DEPLOYMENT'),
    ],

    // Auto-detect container/k8s/cloud resource attributes (container.id,
    // k8s.pod.name, k8s.namespace.name, cloud.region, …) from cgroup
    // facts (via cboxdk/system-metrics), well-known downward-API env vars
    // and OTEL_RESOURCE_ATTRIBUTES. Config service.* keys always win.
    'resource_detection' => env('TELEMETRY_RESOURCE_DETECTION', true),

    // Emit the package's own health as metrics (telemetry.export.*,
    // telemetry.spool.depth, telemetry.export.circuit_open) — who watches
    // the watcher. Cheap; disable only if you truly don't want them.
    'self_metrics' => env('TELEMETRY_SELF_METRICS', true),

    /*
    |--------------------------------------------------------------------------
    | Metric Store
    |--------------------------------------------------------------------------
    |
    | Push instruments (counters, gauges, histograms) write to shared storage
    | so values survive PHP's shared-nothing lifecycle and aggregate across
    | web workers, queue workers and nodes.
    |
    | Supported drivers: "redis", "apcu", "array".
    |
    | We recommend a Redis connection separate from your queue connection.
    |
    */

    'store' => env('TELEMETRY_STORE', 'redis'),

    // Buffer metric writes in memory and flush them aggregated at request/
    // job terminate — 100 increments of one counter cost a single store
    // command. Trade-off: a hard crash loses the unflushed buffer.
    'buffer_writes' => env('TELEMETRY_BUFFER_WRITES', true),

    'stores' => [
        'redis' => [
            'connection' => env('TELEMETRY_REDIS_CONNECTION', 'default'),
            'prefix' => env('TELEMETRY_REDIS_PREFIX', 'telemetry'),
        ],

        'apcu' => [
            'prefix' => env('TELEMETRY_APCU_PREFIX', 'telemetry'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Exporters
    |--------------------------------------------------------------------------
    |
    | Each exporter declares which signals it supports. Traces and events are
    | flushed at request/job terminate; metrics are exported by the scheduled
    | `telemetry:flush` command (OTLP) or scraped on demand (Prometheus).
    |
    */

    'exporters' => env('TELEMETRY_EXPORTERS') === null
        ? []
        : explode(',', (string) env('TELEMETRY_EXPORTERS')),

    'otlp' => [
        'endpoint' => env('TELEMETRY_OTLP_ENDPOINT', env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:4318')),
        // Bearer token for an auth-gated OTLP endpoint (e.g. a shared
        // collector). Sent as `Authorization: Bearer <token>`. Additional
        // headers can come from the OTel-standard OTEL_EXPORTER_OTLP_HEADERS.
        'headers' => array_filter([
            'Authorization' => env('TELEMETRY_OTLP_TOKEN') ? 'Bearer '.env('TELEMETRY_OTLP_TOKEN') : null,
        ]) + $otelHeaders,
        'timeout' => env('TELEMETRY_OTLP_TIMEOUT', 3.0),
        'connect_timeout' => env('TELEMETRY_OTLP_CONNECT_TIMEOUT', 1.0),

        // gzip request bodies above 1 KB.
        'compression' => env('TELEMETRY_OTLP_COMPRESSION', true),

        // High-traffic mode: instead of POSTing at request terminate,
        // spans/events are pushed to a capped Redis list (one RPUSH per
        // request) and `telemetry:flush --daemon` ships them in merged
        // batches every --interval seconds. Drop-oldest above max_items.
        'spool' => [
            'enabled' => env('TELEMETRY_OTLP_SPOOL', false),
            'connection' => env('TELEMETRY_OTLP_SPOOL_CONNECTION', 'default'),
            'key' => env('TELEMETRY_OTLP_SPOOL_KEY', 'telemetry:spool'),
            'max_items' => env('TELEMETRY_OTLP_SPOOL_MAX_ITEMS', 20000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Prometheus Scrape Endpoints
    |--------------------------------------------------------------------------
    |
    | Multiple named endpoints may be exposed, each with its own path,
    | middleware stack and metric filter — e.g. a public endpoint with a
    | narrow allowlist and an internal endpoint with everything.
    |
    */

    'prometheus' => [
        'enabled' => env('TELEMETRY_PROMETHEUS_ENABLED', true),

        'endpoints' => [
            'default' => [
                'path' => env('TELEMETRY_PROMETHEUS_PATH', 'telemetry/metrics'),
                'middleware' => [
                    AllowIps::class,
                ],
                // Only expose metrics whose name matches one of these
                // prefixes. Null exposes everything.
                'only' => null,
            ],
        ],

        // IPs (single or CIDR) allowed by the AllowIps middleware.
        // An empty list allows everyone.
        'allowed_ips' => env('TELEMETRY_ALLOWED_IPS') === null
            ? []
            : explode(',', (string) env('TELEMETRY_ALLOWED_IPS')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Traces
    |--------------------------------------------------------------------------
    |
    | Spans buffer in memory and flush once at terminate. The sample decision
    | is made per trace at the root span and propagated downstream via the
    | W3C traceparent sampled flag.
    |
    */

    'traces' => [
        'sample_rate' => env('TELEMETRY_TRACES_SAMPLE_RATE', 1.0),

        // Force-flush when the in-memory buffer exceeds this many spans
        // (protects long-running jobs and Octane workers).
        'max_buffer' => env('TELEMETRY_TRACES_MAX_BUFFER', 5000),

        // Error spans are exported even from unsampled traces — a
        // 10%-sampled app still surfaces every failing span.
        'always_sample_errors' => env('TELEMETRY_TRACES_ALWAYS_SAMPLE_ERRORS', true),

        // Publish trace_id into Laravel's Context facade at trace start —
        // Sentry (>= 4.x), Flare and every log channel pick it up
        // automatically, closing the loop: error tracker -> trace id ->
        // Tempo waterfall. An explicit Sentry scope tag is also set when
        // the SDK is installed.
        'share_context' => env('TELEMETRY_TRACES_SHARE_CONTEXT', true),

        // Expose the trace id on every response — the support-case
        // reference ("quote id X to support") and the Tempo lookup key.
        // Set to null/empty to disable.
        'response_header' => env('TELEMETRY_TRACES_RESPONSE_HEADER', 'X-Trace-Id'),

        // Trust incoming `traceparent` headers and continue remote traces.
        'continue_incoming' => env('TELEMETRY_TRACES_CONTINUE_INCOMING', true),

        // Tail detail retention: keep detail spans (cache ops, queries)
        // only for traces with errors or slowness — MANY details when it
        // hurts, a lean skeleton + aggregates when all is well. The
        // decision is made at flush, when the whole trace is in memory.
        'details' => [
            'mode' => env('TELEMETRY_TRACES_DETAILS', 'always'), // always | tail
            'slow_request_ms' => env('TELEMETRY_TRACES_SLOW_REQUEST_MS', 1000),
            'slow_span_ms' => env('TELEMETRY_TRACES_SLOW_SPAN_MS', 100),
        ],

        // Also trust the caller's SAMPLING decision. Disable on public
        // edges so clients cannot force sampling on (bypassing your
        // sample rate) or off (hiding from tracing); trace ids are still
        // continued for correlation.
        'trust_incoming_sampling' => env('TELEMETRY_TRACES_TRUST_INCOMING_SAMPLING', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    */

    'events' => [
        // Force-flush when this many events are buffered (protects
        // long-running workers using the telemetry log channel).
        'max_buffer' => env('TELEMETRY_EVENTS_MAX_BUFFER', 5000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Histogram Buckets
    |--------------------------------------------------------------------------
    |
    | Default bucket boundaries for histograms that don't declare their own.
    | Values are in the instrument's native unit (durations: milliseconds).
    |
    */

    'default_buckets' => [1, 5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000],

    /*
    |--------------------------------------------------------------------------
    | Redaction Engine
    |--------------------------------------------------------------------------
    |
    | Every span attribute, span event (exception messages included) and
    | telemetry event passes through the redaction engine at flush time,
    | before any exporter sees it.
    |
    | `keys` match whole dot/underscore segments of attribute keys — the
    | value is replaced entirely. `patterns` are regexes scrubbing secrets
    | embedded in any string value (JWTs, Bearer/Basic credentials, url
    | userinfo by default). Omit a key to keep the built-in defaults; see
    | Redactor::defaultKeys() / defaultPatterns(). Add a custom last-pass
    | hook with Telemetry::redactUsing().
    |
    */

    'redaction' => [
        'enabled' => env('TELEMETRY_REDACTION', true),

        // 'keys' => [...Redactor::defaultKeys(), 'cpr'],
        // 'patterns' => [...Redactor::defaultPatterns(), '/\d{6}-\d{4}/' => '[REDACTED]'],
        // 'safe_keys' => [...Redactor::defaultSafeKeys(), 'my.known_safe.token_bucket'],

        'replacement' => '[REDACTED]',
    ],

    /*
    |--------------------------------------------------------------------------
    | Browser / Frontend Span Ingest (optional)
    |--------------------------------------------------------------------------
    |
    | An opt-in endpoint the browser posts its own spans to (page load,
    | fetch timings, JS errors). When the browser also propagates its W3C
    | traceparent to your backend, browser and backend spans share one
    | trace id — end-to-end distributed tracing in a single waterfall.
    |
    | A browser endpoint cannot hold a secret, so it is protected by
    | throttling, strict payload bounding and optional head sampling —
    | never a bearer token. Add your own auth via `middleware` if the app
    | is behind login.
    |
    */

    'ingest' => [
        'spans' => [
            'enabled' => env('TELEMETRY_INGEST_SPANS', false),
            'path' => env('TELEMETRY_INGEST_SPANS_PATH', 'telemetry/spans'),
            // Flood defenses (a browser endpoint is world-reachable).
            'middleware' => ['throttle:300,1'],
            'max_spans' => 128,          // per batch; excess dropped
            'max_attributes' => 32,      // per span
            'sample_rate' => 1.0,        // head sampling; drop a fraction of batches

            // The bundled zero-build RUM script (@telemetryBrowser).
            'asset_path' => env('TELEMETRY_INGEST_SPANS_ASSET', 'telemetry/browser.js'),
            'browser' => [
                'fetch' => true,   // instrument fetch + propagate traceparent (same-origin)
                'errors' => true,  // capture uncaught JS errors as error spans
                'sample' => 1.0,   // client-side head sampling (0-1)
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Source Maps (optional — for symbolicating browser stacks)
    |--------------------------------------------------------------------------
    |
    | An upload endpoint the build pipeline POSTs source maps to (via the
    | @cboxdk/telemetry-browser uploader), keyed by release. telemetry-ui
    | then resolves minified browser frames back to the original source.
    | Uploads come from CI, so this is bearer-token gated (set a token) —
    | it is never accidentally open.
    |
    */

    'sourcemaps' => [
        'enabled' => env('TELEMETRY_SOURCEMAPS', false),
        'token' => env('TELEMETRY_SOURCEMAPS_TOKEN'),
        'path' => env('TELEMETRY_SOURCEMAPS_PATH', 'telemetry/sourcemaps'),
        'disk' => env('TELEMETRY_SOURCEMAPS_DISK', 'local'),
        'prefix' => 'telemetry/sourcemaps',
        'middleware' => [],
        'max_bytes' => 20 * 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Analytics (opt-in, default OFF)
    |--------------------------------------------------------------------------
    |
    | An additive analytics layer on top of the telemetry we already collect
    | — a shared session.id across browser + server, so visits (not just
    | single traces) can be analysed. Everything here is off by default and
    | changes NOTHING when disabled. The identity and geo resolution are
    | fully overridable via hooks, so you can source them from Cloudflare
    | headers (CF-Connecting-IP, CF-IPCountry, CF-Ray), a first-party
    | cookie, or your own logic — see Telemetry::resolveSessionUsing() and
    | Telemetry::resolveClientGeoUsing().
    |
    */

    'analytics' => [
        'enabled' => env('TELEMETRY_ANALYTICS', false),

        'session' => [
            // Cookieless by default: the built-in session.id is a
            // daily-rotating, salted hash so a raw IP never becomes a
            // grouping key. Override the whole thing with a hook for exact,
            // cookie-based visits.
            'salt' => env('TELEMETRY_ANALYTICS_SALT'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic Instrumentation
    |--------------------------------------------------------------------------
    */

    'instrument' => [
        // HTTP server spans + http.server.* metrics via global middleware.
        'requests' => env('TELEMETRY_INSTRUMENT_REQUESTS', true),

        // Add the domain as a server.address label on http.server.*
        // metrics. Routes with a domain pattern report the PATTERN
        // ("{tenant}.app.example"), keeping wildcard-tenant cardinality
        // bounded; everything else reports the concrete host.
        'host_label' => env('TELEMETRY_INSTRUMENT_HOST_LABEL', true),

        // Request headers captured on the span as http.request.header.*
        // (allowlist, lowercase). Credentials and session headers
        // (Authorization, Cookie, X-Api-Key, …) are denylisted and never
        // captured, even if listed here.
        'request_headers' => ['accept', 'accept-language', 'content-type', 'origin', 'referer', 'x-forwarded-for', 'x-requested-with'],

        // Response headers captured as http.response.header.*.
        'response_headers' => ['content-type', 'cache-control'],

        // Job spans + queue metrics, with trace continuation from dispatch.
        'jobs' => env('TELEMETRY_INSTRUMENT_JOBS', true),

        // db.client.* query spans (only recorded inside a sampled trace).
        'queries' => env('TELEMETRY_INSTRUMENT_QUERIES', true),

        // Skip query spans faster than this (ms) — a noise floor for
        // N+1-heavy codepaths. 0 records everything.
        'queries_min_duration' => env('TELEMETRY_INSTRUMENT_QUERIES_MIN_DURATION', 0),

        // Artisan command spans.
        'commands' => env('TELEMETRY_INSTRUMENT_COMMANDS', false),

        // Tag request spans with the authenticated user's id (enduser.id)
        // for per-user trace filtering. Id only — never name/email.
        'user' => env('TELEMETRY_INSTRUMENT_USER', true),

        // Peak memory + CPU time per request and per queue job — span
        // attributes (php.memory.peak_bytes, php.cpu.time_ms) and
        // histograms (http.server.memory.peak / cpu.time, queue.job.*).
        'resources' => env('TELEMETRY_INSTRUMENT_RESOURCES', true),

        // Scheduled task spans + schedule.tasks.{processed,failed,skipped}
        // counters and schedule.task.duration histogram.
        'scheduled_tasks' => env('TELEMETRY_INSTRUMENT_SCHEDULED_TASKS', true),

        // cache.operations{operation, store} counters (hit/miss/write/
        // forget). Off by default — hot caches are chatty.
        'cache' => env('TELEMETRY_INSTRUMENT_CACHE', false),

        // Nightwatch-style timeline spans per cache operation — key,
        // store and duration on every hit/miss/write/forget in the
        // trace waterfall. Keys are safe on spans (per-occurrence, not
        // aggregated). Off by default; the span buffer cap bounds
        // pathological requests.
        'cache_spans' => env('TELEMETRY_INSTRUMENT_CACHE_SPANS', false),

        // Authentication lifecycle: auth.events{event, guard} counters —
        // login/logout/failed/lockout/password_reset/registered/verified.
        // A spike in `failed` is a credential attack in progress.
        'auth' => env('TELEMETRY_INSTRUMENT_AUTH', true),

        // Database transaction spans (BEGIN..COMMIT/ROLLBACK, nested via
        // savepoints) + db.transactions.rolled_back counter.
        'transactions' => env('TELEMETRY_INSTRUMENT_TRANSACTIONS', true),

        // Eloquent: model.hydrations root-span tally (the N+1 smell) +
        // models.events{model,event} write counters + models.pruned.
        'models' => env('TELEMETRY_INSTRUMENT_MODELS', true),

        // Job batch lifecycle counters: bus.batches{event, name}.
        'batches' => env('TELEMETRY_INSTRUMENT_BATCHES', true),

        // Redis command spans + redis.commands counter. Off by default —
        // high volume. The telemetry store/spool connections are always
        // ignored (self-instrumentation would loop); override the ignore
        // list with redis_ignore_connections.
        'redis' => env('TELEMETRY_INSTRUMENT_REDIS', false),
        'redis_ignore_connections' => null,

        // Gate/policy checks: authorization.checks{ability, result}
        // counter + gate.check.count / gate.denied.count root-span
        // tallies. Ability names are code identifiers (bounded).
        'gates' => env('TELEMETRY_INSTRUMENT_GATES', true),

        // A span per Blade/PHP view render — templates, partials and
        // components, naturally nested with real durations. Detail-marked
        // (tail mode trims them from healthy fast traces). The root span
        // carries a view.render.count tally regardless.
        'views' => env('TELEMETRY_INSTRUMENT_VIEWS', true),

        // session.driver + session.hash (sha256-truncated, NEVER the raw
        // id) on request root spans — one TraceQL query follows a whole
        // visitor journey across requests.
        'session' => env('TELEMETRY_INSTRUMENT_SESSION', true),

        // Cache stores that are never recorded (neither counters nor
        // spans) — e.g. a store used exclusively by a framework
        // subsystem you instrument separately. For key-level control,
        // see Telemetry::classifyCacheKeysUsing().
        'cache_ignore_stores' => [],

        // mail.send spans + mail.sent counter.
        'mail' => env('TELEMETRY_INSTRUMENT_MAIL', true),

        // notification.send spans + notifications.sent{channel,notification}.
        'notifications' => env('TELEMETRY_INSTRUMENT_NOTIFICATIONS', true),

        // Outgoing Http-client spans + http.client.request.duration
        // histogram by host/method/status.
        'http_client' => env('TELEMETRY_INSTRUMENT_HTTP_CLIENT', true),

        // exceptions.reported counter — includes HANDLED exceptions that
        // report() swallows.
        'exceptions' => env('TELEMETRY_INSTRUMENT_EXCEPTIONS', true),

        // Attach the source lines around the throw site to exception
        // records (exception.source) — the "feels like Sentry" detail.
        // Off by default: it reads the source file on every reported
        // exception.
        'exception_source' => env('TELEMETRY_INSTRUMENT_EXCEPTION_SOURCE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Trace Propagation
    |--------------------------------------------------------------------------
    |
    | Dispatched jobs carry the full W3C traceparent (trace id AND parent
    | span id) so job spans appear as children of the dispatch site.
    |
    */

    'queue' => [
        'propagate' => env('TELEMETRY_QUEUE_PROPAGATE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Host & Process Monitor
    |--------------------------------------------------------------------------
    |
    | The optional node_exporter analog (`telemetry:monitor`). Cron mode:
    | Schedule::command('telemetry:monitor --once')->everyMinute() — or a
    | daemon under supervisor. Foreign long-running processes are found
    | by pgrep pattern and sampled for aggregate RSS + count.
    |
    */

    'monitor' => [
        'interval' => env('TELEMETRY_MONITOR_INTERVAL', 15),

        'processes' => [
            // 'queue-workers' => 'queue:work',
            // 'horizon' => 'horizon',
            // 'reverb' => 'reverb:start',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Built-in Providers
    |--------------------------------------------------------------------------
    */

    'providers' => [
        // Host CPU / memory / load metrics via cboxdk/system-metrics
        // (auto-enabled when the package is installed).
        'system' => [
            'enabled' => env('TELEMETRY_SYSTEM_METRICS', true),

            // Sampling interval for CPU utilization at collect time.
            // Set to 0 to skip CPU utilization entirely.
            'cpu_interval' => env('TELEMETRY_SYSTEM_CPU_INTERVAL', 0.1),
        ],
    ],

];
