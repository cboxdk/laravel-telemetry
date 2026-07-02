<?php

declare(strict_types=1);
use Cbox\Telemetry\Http\Middleware\AllowIps;

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
        'name' => env('TELEMETRY_SERVICE_NAME', env('APP_NAME', 'laravel')),
        'namespace' => env('TELEMETRY_SERVICE_NAMESPACE'),
        'version' => env('TELEMETRY_SERVICE_VERSION'),
        'environment' => env('TELEMETRY_ENVIRONMENT', env('APP_ENV', 'production')),

        // Deployment marker (git sha, release tag) — shows on every
        // signal so regressions map to deploys.
        'deployment' => env('TELEMETRY_DEPLOYMENT'),
    ],

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
        'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:4318'),
        'headers' => [
            // 'Authorization' => 'Bearer ...',
        ],
        'timeout' => env('TELEMETRY_OTLP_TIMEOUT', 3.0),
        'connect_timeout' => env('TELEMETRY_OTLP_CONNECT_TIMEOUT', 1.0),

        // gzip request bodies above 1 KB.
        'compression' => env('TELEMETRY_OTLP_COMPRESSION', true),
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

        // Trust incoming `traceparent` headers and continue remote traces.
        'continue_incoming' => env('TELEMETRY_TRACES_CONTINUE_INCOMING', true),

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
    | Automatic Instrumentation
    |--------------------------------------------------------------------------
    */

    'instrument' => [
        // HTTP server spans + http.server.* metrics via global middleware.
        'requests' => env('TELEMETRY_INSTRUMENT_REQUESTS', true),

        // Job spans + queue metrics, with trace continuation from dispatch.
        'jobs' => env('TELEMETRY_INSTRUMENT_JOBS', true),

        // db.client.* query spans (only recorded inside a sampled trace).
        'queries' => env('TELEMETRY_INSTRUMENT_QUERIES', true),

        // Skip query spans faster than this (ms) — a noise floor for
        // N+1-heavy codepaths. 0 records everything.
        'queries_min_duration' => env('TELEMETRY_QUERIES_MIN_DURATION', 0),

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
