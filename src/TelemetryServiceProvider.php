<?php

declare(strict_types=1);

namespace Cbox\Telemetry;

use Cbox\SystemMetrics\SystemMetrics;
use Cbox\Telemetry\Console\DashboardsCommand;
use Cbox\Telemetry\Console\DeployCommand;
use Cbox\Telemetry\Console\DoctorCommand;
use Cbox\Telemetry\Console\FlushCommand;
use Cbox\Telemetry\Console\MonitorCommand;
use Cbox\Telemetry\Contracts\Exporter;
use Cbox\Telemetry\Contracts\ManagesRequestState;
use Cbox\Telemetry\Contracts\MetricStore;
use Cbox\Telemetry\Events\TelemetryEvent;
use Cbox\Telemetry\Exporters\NullExporter;
use Cbox\Telemetry\Exporters\Otlp\OtlpExporter;
use Cbox\Telemetry\Exporters\Otlp\OtlpSerializer;
use Cbox\Telemetry\Exporters\Otlp\OtlpTransport;
use Cbox\Telemetry\Exporters\Prometheus\PrometheusRenderer;
use Cbox\Telemetry\Exporters\Spool\RedisSpool;
use Cbox\Telemetry\Exporters\Spool\Spool;
use Cbox\Telemetry\Exporters\Spool\SpoolingOtlpExporter;
use Cbox\Telemetry\Http\Controllers\BrowserAssetController;
use Cbox\Telemetry\Http\Controllers\PrometheusController;
use Cbox\Telemetry\Http\Controllers\SourcemapController;
use Cbox\Telemetry\Http\Controllers\SpanIngestController;
use Cbox\Telemetry\Http\Middleware\FlushBrowserIngest;
use Cbox\Telemetry\Http\Middleware\TraceRequest;
use Cbox\Telemetry\Instrumentation\AuthInstrumentation;
use Cbox\Telemetry\Instrumentation\BroadcastingInstrumentation;
use Cbox\Telemetry\Instrumentation\BusInstrumentation;
use Cbox\Telemetry\Instrumentation\CacheInstrumentation;
use Cbox\Telemetry\Instrumentation\CommandInstrumentation;
use Cbox\Telemetry\Instrumentation\FilesystemInstrumentation;
use Cbox\Telemetry\Instrumentation\HorizonInstrumentation;
use Cbox\Telemetry\Instrumentation\HttpClientInstrumentation;
use Cbox\Telemetry\Instrumentation\LivewireInstrumentation;
use Cbox\Telemetry\Instrumentation\MailInstrumentation;
use Cbox\Telemetry\Instrumentation\ModelInstrumentation;
use Cbox\Telemetry\Instrumentation\NotificationInstrumentation;
use Cbox\Telemetry\Instrumentation\PennantInstrumentation;
use Cbox\Telemetry\Instrumentation\QueryInstrumentation;
use Cbox\Telemetry\Instrumentation\QueueInstrumentation;
use Cbox\Telemetry\Instrumentation\RedisInstrumentation;
use Cbox\Telemetry\Instrumentation\ReverbInstrumentation;
use Cbox\Telemetry\Instrumentation\ScheduleInstrumentation;
use Cbox\Telemetry\Instrumentation\TransactionInstrumentation;
use Cbox\Telemetry\Instrumentation\ViewInstrumentation;
use Cbox\Telemetry\Logging\TelemetryLogHandler;
use Cbox\Telemetry\Metrics\Registry;
use Cbox\Telemetry\Metrics\Stores\ApcuMetricStore;
use Cbox\Telemetry\Metrics\Stores\ArrayMetricStore;
use Cbox\Telemetry\Metrics\Stores\BufferedMetricStore;
use Cbox\Telemetry\Metrics\Stores\NullMetricStore;
use Cbox\Telemetry\Metrics\Stores\RedisMetricStore;
use Cbox\Telemetry\Providers\SystemMetricsProvider;
use Cbox\Telemetry\Support\Baggage;
use Cbox\Telemetry\Support\Cast;
use Cbox\Telemetry\Support\ExceptionAttributes;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\Support\GeoResolver;
use Cbox\Telemetry\Support\GitVersion;
use Cbox\Telemetry\Support\Redactor;
use Cbox\Telemetry\Support\ResourceDetector;
use Cbox\Telemetry\Support\Symbolicator;
use Cbox\Telemetry\Tracing\Tracer;
use Illuminate\Auth\Access\Response;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Contracts\Routing\Registrar as Router;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Log\LogManager;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Horizon\Events\SupervisorLooped;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\TickReceived;
use Laravel\Pennant\Events\FeatureRetrieved;
use Laravel\Reverb\Events\MessageSent;
use Livewire\Livewire;
use Monolog\Level;
use Monolog\Logger;

class TelemetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OtlpTransport::class, function (Application $app) {
            $config = $app->make('config');

            return new OtlpTransport(
                endpoint: Cast::string($config->get('telemetry.otlp.endpoint')),
                headers: Cast::stringMap($config->get('telemetry.otlp.headers', [])),
                timeout: Cast::float($config->get('telemetry.otlp.timeout'), 3.0),
                connectTimeout: Cast::float($config->get('telemetry.otlp.connect_timeout'), 1.0),
                compress: Cast::bool($config->get('telemetry.otlp.compression'), true),
            );
        });

        $this->app->singleton(Spool::class, function (Application $app) {
            $config = $app->make('config');

            return new RedisSpool(
                redis: $app->make('redis'),
                connection: Cast::string($config->get('telemetry.otlp.spool.connection'), 'default'),
                key: Cast::string($config->get('telemetry.otlp.spool.key'), 'telemetry:spool'),
                maxItems: Cast::int($config->get('telemetry.otlp.spool.max_items'), 20000),
            );
        });

        $this->mergeConfigFrom(__DIR__.'/../config/telemetry.php', 'telemetry');

        $this->app->singleton(MetricStore::class, fn (Application $app) => $this->buildStore($app));

        $this->app->singleton(Registry::class, function (Application $app) {
            /** @var list<float> $buckets */
            $buckets = $app->make('config')->get('telemetry.default_buckets', []);

            return new Registry(
                $app->make(MetricStore::class),
                $buckets,
                // Lazy: Tracer isn't resolved until a histogram is actually
                // recorded, long after both singletons exist — no circular
                // dependency at construction time.
                function () use ($app): ?string {
                    $span = $app->make(Tracer::class)->currentSpan();

                    return $span !== null && $span->sampled ? $span->traceId : null;
                },
            );
        });

        $this->app->singleton(Tracer::class, function (Application $app) {
            $config = $app->make('config');
            $enabled = (bool) $config->get('telemetry.enabled');

            $tracer = new Tracer(
                sampleRate: $enabled ? Cast::float($config->get('telemetry.traces.sample_rate'), 1.0) : 0.0,
                maxBuffer: Cast::int($config->get('telemetry.traces.max_buffer'), 5000),
                alwaysSampleErrors: $enabled && (bool) $config->get('telemetry.traces.always_sample_errors', true),
            );

            if ($enabled && $config->get('telemetry.instrument.resources', true)) {
                $tracer->measureSpanResources();
            }

            return $tracer;
        });

        $this->app->singleton(TelemetryManager::class, function (Application $app) {
            $manager = new TelemetryManager(
                enabled: (bool) $app->make('config')->get('telemetry.enabled'),
                registry: $app->make(Registry::class),
                tracer: $app->make(Tracer::class),
                resource: $this->buildResource($app),
                maxBufferedEvents: Cast::int($app->make('config')->get('telemetry.events.max_buffer'), 5000),
                tailDetails: $app->make('config')->get('telemetry.traces.details.mode', 'always') === 'tail',
                slowRequestMs: Cast::float($app->make('config')->get('telemetry.traces.details.slow_request_ms'), 1000),
                slowSpanMs: Cast::float($app->make('config')->get('telemetry.traces.details.slow_span_ms'), 100),
                redactor: Redactor::fromConfig(Cast::stringKeyedArray($app->make('config')->get('telemetry.redaction', []))),
                selfMetrics: (bool) $app->make('config')->get('telemetry.self_metrics', true),
            );

            foreach ($this->buildExporters($app) as $exporter) {
                $manager->addExporter($exporter);
            }

            return $manager;
        });

        $this->app->alias(TelemetryManager::class, 'telemetry');

        $this->app->singleton(PrometheusRenderer::class);

        // Resolvable by telemetry-ui to symbolicate browser stacks.
        $this->app->singleton(Symbolicator::class, function (Application $app) {
            $config = Cast::stringKeyedArray($app->make('config')->get('telemetry.sourcemaps', []));

            return new Symbolicator(
                $app->make('filesystem')->disk(Cast::string($config['disk'] ?? null, 'local')),
                Cast::string($config['prefix'] ?? null, 'telemetry/sourcemaps'),
            );
        });

        // Analytics geo — lazy MaxMind reader (no boot-time I/O), cached for
        // the process. A no-op without the optional geoip2/geoip2 package.
        $this->app->singleton(GeoResolver::class, function (Application $app) {
            $db = $app->make('config')->get('telemetry.analytics.geo.database');

            return new GeoResolver(is_string($db) && $db !== '' ? $db : null);
        });

        // Register the `telemetry` log driver in register(), not boot(): the
        // `log` manager may be resolved (and a `stack` channel built) before
        // this provider boots — if the driver isn't registered by then, the
        // telemetry sub-channel silently falls back to an emergency handler
        // and no logs ever reach telemetry.
        $this->registerLogDriver();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/telemetry.php' => config_path('telemetry.php'),
            ], 'telemetry-config');

            $this->publishes([
                __DIR__.'/../resources/js/browser.js' => public_path('vendor/telemetry/browser.js'),
            ], 'telemetry-assets');

            $this->commands([FlushCommand::class,
                DeployCommand::class, DoctorCommand::class, DashboardsCommand::class, MonitorCommand::class]);
        }

        if (! $this->app->make('config')->get('telemetry.enabled')) {
            return;
        }

        $this->registerPrometheusRoutes();
        $this->registerSpanIngestRoute();
        $this->registerSourcemapRoute();
        $this->registerBladeDirectives();
        $this->registerRequestInstrumentation();
        $this->registerQueueInstrumentation();
        $this->registerQueryInstrumentation();
        $this->registerCommandInstrumentation();
        $this->registerScheduleInstrumentation();
        $this->registerEventInstrumentations();
        $this->registerSystemMetricsProvider();
        $this->registerSelfMetrics();
        $this->registerOctaneReset();
        $this->registerHttpClientMacro();
        $this->registerAboutCommand();
    }

    /**
     * The package's own health as pull gauges — who watches the watcher.
     * Export duration/outcome counters are recorded inline on the export
     * path; these two are point-in-time state read at scrape/flush.
     */
    private function registerSelfMetrics(): void
    {
        if (! $this->app->make('config')->get('telemetry.self_metrics', true)) {
            return;
        }

        $config = $this->app->make('config');
        $registry = $this->app->make(Registry::class);

        // Only meaningful when OTLP is actually in use — the breaker is an
        // OtlpExporter concern, and an unused gauge is just scrape noise.
        if (in_array('otlp', (array) $config->get('telemetry.exporters', []), true)) {
            // 1 while the per-process OTLP circuit breaker is open (recent
            // transport failure), 0 otherwise — alert on sustained 1.
            $registry->gauge(
                'telemetry.export.circuit_open',
                fn (): float => OtlpExporter::circuitOpen() ? 1.0 : 0.0,
                description: 'OTLP export circuit breaker state (1 = open)',
                unit: '1',
            );
        }

        // Spool backlog — a climbing depth means the daemon isn't keeping
        // up (or is down). Only meaningful when the spool is enabled.
        if ($config->get('telemetry.otlp.spool.enabled', false)) {
            $registry->gauge(
                'telemetry.spool.depth',
                fn (): float => (float) (FailSafe::guard(fn () => $this->app->make(Spool::class)->size()) ?? 0),
                description: 'Pending payloads in the OTLP spool',
                unit: '',
            );
        }
    }

    /**
     * The `telemetry` log channel: ships log records as trace-correlated
     * OTLP log records. Add it to a stack in config/logging.php:
     *
     *     'telemetry' => ['driver' => 'telemetry', 'level' => 'info'],
     */
    private function registerLogDriver(): void
    {
        if (! class_exists(Logger::class)) {
            return;
        }

        $this->callAfterResolving('log', function (LogManager $log) {
            $log->extend('telemetry', function ($app, array $config) {
                return new Logger('telemetry', [
                    new TelemetryLogHandler(
                        fn (): TelemetryManager => $app->make(TelemetryManager::class),
                        $config['level'] ?? Level::Debug,
                    ),
                ]);
            });
        });
    }

    /**
     * Http::withTraceparent() attaches the current W3C trace context — AND
     * baggage (Telemetry::context() dimensions), its standard sibling
     * header — to an outbound request, so the downstream service
     * continues the trace with the SAME custom dimensions, not just the
     * trace id:
     *
     *     Http::withTraceparent()->post($url, $payload);
     */
    private function registerHttpClientMacro(): void
    {
        if (! class_exists(PendingRequest::class)) {
            return;
        }

        $app = $this->app;

        PendingRequest::macro('withTraceparent', function () use ($app) {
            /** @var PendingRequest $this */
            $telemetry = $app->make(TelemetryManager::class);
            $traceparent = $telemetry->traceparent();
            $baggage = Baggage::encode($telemetry->contextAttributes());

            return $this->withHeaders(array_filter([
                'traceparent' => $traceparent,
                'baggage' => $baggage,
            ], static fn (?string $value): bool => $value !== null));
        });
    }

    private function registerAboutCommand(): void
    {
        if (! class_exists(AboutCommand::class)) {
            return;
        }

        AboutCommand::add('Telemetry', fn () => [
            'Enabled' => config('telemetry.enabled') ? '<fg=green;options=bold>ENABLED</>' : '<fg=yellow;options=bold>DISABLED</>',
            'Metric Store' => Cast::string(config('telemetry.store'), 'redis'),
            'Exporters' => implode(', ', Cast::stringList(config('telemetry.exporters', []))) ?: 'none',
            'Prometheus' => config('telemetry.prometheus.enabled')
                ? collect(Cast::array(config('telemetry.prometheus.endpoints')))
                    ->map(fn ($e) => '/'.ltrim(Cast::string(Cast::stringKeyedArray($e)['path'] ?? null), '/'))
                    ->implode(', ')
                    .(config('telemetry.prometheus.allowed_ips') === [] ? ' <fg=yellow;options=bold>(OPEN — no IP allowlist)</>' : '')
                : 'off',
            'Trace Sample Rate' => Cast::string(config('telemetry.traces.sample_rate'), '1.0'),
            'System Metrics' => class_exists(SystemMetrics::class) && config('telemetry.providers.system.enabled')
                ? 'active'
                : (config('telemetry.providers.system.enabled') ? 'install cboxdk/system-metrics to activate' : 'off'),
        ]);
    }

    private function buildStore(Application $app): MetricStore
    {
        $config = $app->make('config');

        if (! $config->get('telemetry.enabled')) {
            return new NullMetricStore;
        }

        $driver = Cast::string($config->get('telemetry.store'), 'redis');

        $store = match ($driver) {
            'redis' => new RedisMetricStore(
                redis: $app->make(RedisFactory::class),
                connection: Cast::string($config->get('telemetry.stores.redis.connection'), 'default'),
                prefix: Cast::string($config->get('telemetry.stores.redis.prefix'), 'telemetry'),
            ),
            'apcu' => new ApcuMetricStore(
                prefix: Cast::string($config->get('telemetry.stores.apcu.prefix'), 'telemetry'),
            ),
            'array' => new ArrayMetricStore,
            default => new NullMetricStore,
        };

        // Wrap networked/shared stores in the write buffer; the array and
        // null stores are in-process already and gain nothing from it.
        if (in_array($driver, ['redis', 'apcu'], true) && $config->get('telemetry.buffer_writes', true)) {
            return new BufferedMetricStore($store);
        }

        return $store;
    }

    /**
     * @return array<string, scalar>
     */
    private function buildResource(Application $app): array
    {
        $config = $app->make('config');

        $resource = [
            'service.name' => Cast::string($config->get('telemetry.service.name'), 'laravel'),
            'deployment.environment.name' => Cast::string($config->get('telemetry.service.environment'), 'production'),
            'host.name' => (string) gethostname(),
            'telemetry.sdk.name' => 'cboxdk/laravel-telemetry',
            'telemetry.sdk.language' => 'php',
            'process.runtime.name' => 'php',
            'process.runtime.version' => PHP_VERSION,
            'laravel.version' => $app->version(),
        ];

        if (is_string($namespace = $config->get('telemetry.service.namespace'))) {
            $resource['service.namespace'] = $namespace;
        }

        if (is_string($version = $config->get('telemetry.service.version'))) {
            $resource['service.version'] = $version;
        }

        // Deployment marker: explicit config wins; otherwise the current
        // git commit identifies the deploy (two file reads, no exec).
        $deployment = $config->get('telemetry.service.deployment');
        $deployment = is_string($deployment) && $deployment !== '' ? $deployment : GitVersion::detect($app->basePath());

        if ($deployment !== null) {
            $resource['deployment.id'] = $deployment;
        }

        // Container/k8s/cloud attributes fill in around the config-derived
        // keys above — detected keys never overwrite explicit config, so
        // service.name and friends stay authoritative.
        if ($config->get('telemetry.resource_detection', true)) {
            $resource += ResourceDetector::detect();
        }

        return $resource;
    }

    /**
     * @return list<Exporter>
     */
    private function buildExporters(Application $app): array
    {
        $config = $app->make('config');

        /** @var list<string> $names */
        $names = $config->get('telemetry.exporters', []);

        $exporters = [];

        foreach ($names as $name) {
            $exporters[] = match (true) {
                $name === 'otlp' => $this->buildOtlpExporter($app, $config),
                $name === 'null' => new NullExporter,
                // Custom exporters: reference the class name in config,
                // resolved through the container.
                class_exists($name) => $app->make($name),
                default => new NullExporter,
            };
        }

        /** @var list<Exporter> $exporters */
        return $exporters;
    }

    /**
     * Direct OTLP, or — with the spool enabled — spans/events buffered
     * in Redis for `telemetry:flush --daemon` to ship in merged batches.
     */
    private function buildOtlpExporter(Application $app, Repository $config): Exporter
    {
        $direct = new OtlpExporter(
            $app->make(OtlpTransport::class),
            $serializer = new OtlpSerializer($this->buildResource($app)),
        );

        if (! $config->get('telemetry.otlp.spool.enabled', false)) {
            return $direct;
        }

        return new SpoolingOtlpExporter($direct, $serializer, $app->make(Spool::class));
    }

    /**
     * The optional browser/RUM span ingest route. Off by default; when on,
     * the frontend POSTs its spans here and they join the same trace as
     * the backend. Protected by throttling + payload bounding, not a token.
     */
    /**
     * The source map upload endpoint (bearer-token gated). Off by default.
     */
    private function registerSourcemapRoute(): void
    {
        $config = Cast::stringKeyedArray($this->app->make('config')->get('telemetry.sourcemaps', []));

        if (! ($config['enabled'] ?? false)) {
            return;
        }

        /** @var Router $router */
        $router = $this->app->make(Router::class);

        $router->post(Cast::string($config['path'] ?? null, 'telemetry/sourcemaps'), SourcemapController::class)
            ->middleware(Cast::stringList($config['middleware'] ?? []))
            ->name('telemetry.sourcemaps');
    }

    /**
     * @telemetryTraceparent — renders a <meta name="traceparent"> so the
     * browser can parent its RUM spans to the current server trace. A no-op
     * when no trace is active.
     */
    private function registerBladeDirectives(): void
    {
        if (! class_exists(Blade::class)) {
            return;
        }

        Blade::directive('telemetryTraceparent', static fn (): string => "<?php \$__tp = app('telemetry')->traceparent(); if (\$__tp !== null) { echo '<meta name=\"traceparent\" content=\"'.htmlspecialchars(\$__tp, ENT_QUOTES).'\">'; } ?>");
    }

    private function registerSpanIngestRoute(): void
    {
        $config = Cast::stringKeyedArray($this->app->make('config')->get('telemetry.ingest.spans', []));

        if (! ($config['enabled'] ?? false)) {
            return;
        }

        /** @var Router $router */
        $router = $this->app->make(Router::class);

        $router->post(Cast::string($config['path'] ?? null, 'telemetry/spans'), SpanIngestController::class)
            ->middleware([...Cast::stringList($config['middleware'] ?? []), FlushBrowserIngest::class])
            ->defaults('telemetryIngest', $config)
            ->name('telemetry.ingest.spans');

        // The zero-build RUM script served for @telemetryBrowser.
        $router->get(Cast::string($config['asset_path'] ?? null, 'telemetry/browser.js'), BrowserAssetController::class)
            ->name('telemetry.ingest.asset');
    }

    private function registerPrometheusRoutes(): void
    {
        if (! $this->app->make('config')->get('telemetry.prometheus.enabled')) {
            return;
        }

        /** @var Router $router */
        $router = $this->app->make(Router::class);

        /** @var array<string, array{path?: string, middleware?: array<int, class-string>}> $endpoints */
        $endpoints = $this->app->make('config')->get('telemetry.prometheus.endpoints', []);

        foreach ($endpoints as $name => $endpoint) {
            $router->get($endpoint['path'] ?? 'telemetry/metrics', PrometheusController::class)
                ->middleware($endpoint['middleware'] ?? [])
                ->defaults('telemetryEndpoint', $name)
                ->name("telemetry.prometheus.{$name}");
        }
    }

    private function registerRequestInstrumentation(): void
    {
        if (! $this->app->make('config')->get('telemetry.instrument.requests')) {
            return;
        }

        $this->app->booted(function (Application $app) {
            $kernel = $app->make(HttpKernel::class);

            if (method_exists($kernel, 'pushMiddleware')) {
                $kernel->pushMiddleware(TraceRequest::class);
            }
        });

        // The login POST authenticates AFTER the span starts, and logout
        // empties the guard BEFORE terminate — remember the identity so
        // both request types still get user attribution.
        if ($this->app->make('config')->get('telemetry.instrument.user', true)) {
            $events = $this->app->make(Dispatcher::class);

            $remember = function (object $event): void {
                FailSafe::guard(function () use ($event) {
                    /** @var Login|Logout $event */
                    if ($event->user === null) {
                        return;
                    }

                    $this->app->make(TelemetryManager::class)->rememberAuthenticatedUser([
                        'id' => Cast::string($event->user->getAuthIdentifier()),
                        'type' => Str::snake(class_basename($event->user)),
                        'guard' => $event->guard,
                    ]);
                });
            };

            $events->listen(Login::class, $remember);
            $events->listen(Logout::class, $remember);
        }
    }

    private function registerQueueInstrumentation(): void
    {
        $config = $this->app->make('config');

        $propagate = (bool) $config->get('telemetry.queue.propagate');
        $instrument = (bool) $config->get('telemetry.instrument.jobs');

        if (! $propagate && ! $instrument) {
            return;
        }

        $this->app->singleton(QueueInstrumentation::class);

        $this->callAfterResolving('queue', function (QueueManager $queue, Application $app) use ($propagate, $instrument) {
            $app->make(QueueInstrumentation::class)->register(
                $queue,
                $app->make(Dispatcher::class),
                $propagate,
                $instrument,
            );
        });
    }

    private function registerQueryInstrumentation(): void
    {
        if (! $this->app->make('config')->get('telemetry.instrument.queries')) {
            return;
        }

        $config = $this->app->make('config');

        $this->app->make(QueryInstrumentation::class)->register(
            $this->app->make(Dispatcher::class),
            Cast::float($config->get('telemetry.instrument.queries_min_duration'), 0.0),
            (bool) $config->get('telemetry.instrument.query_duplicates', true),
            Cast::int($config->get('telemetry.instrument.query_duplicates_threshold'), 3),
        );
    }

    private function registerCommandInstrumentation(): void
    {
        if (! $this->app->runningInConsole() || ! $this->app->make('config')->get('telemetry.instrument.commands')) {
            return;
        }

        $this->app->singleton(CommandInstrumentation::class);

        $this->app->make(CommandInstrumentation::class)->register(
            $this->app->make(Dispatcher::class),
        );
    }

    private function registerScheduleInstrumentation(): void
    {
        if (! $this->app->runningInConsole() || ! $this->app->make('config')->get('telemetry.instrument.scheduled_tasks', true)) {
            return;
        }

        $this->app->singleton(ScheduleInstrumentation::class);

        $this->app->make(ScheduleInstrumentation::class)->register(
            $this->app->make(Dispatcher::class),
        );
    }

    private function registerEventInstrumentations(): void
    {
        $config = $this->app->make('config');
        $events = $this->app->make(Dispatcher::class);

        $cacheCounters = (bool) $config->get('telemetry.instrument.cache', false);
        $cacheSpans = (bool) $config->get('telemetry.instrument.cache_spans', false);

        if ($cacheCounters || $cacheSpans) {
            $this->app->singleton(CacheInstrumentation::class);
            $this->app->make(CacheInstrumentation::class)->register(
                $events,
                $cacheCounters,
                $cacheSpans,
                array_values(array_filter((array) $config->get('telemetry.instrument.cache_ignore_stores', []), is_string(...))),
            );
        }

        if ($config->get('telemetry.instrument.mail', true)) {
            $this->app->singleton(MailInstrumentation::class);
            $this->app->make(MailInstrumentation::class)->register($events);
        }

        if ($config->get('telemetry.instrument.auth', true)) {
            (new AuthInstrumentation($this->app))->register($events);
        }

        if ($config->get('telemetry.instrument.transactions', true)) {
            $this->app->singleton(TransactionInstrumentation::class);
            $this->app->make(TransactionInstrumentation::class)->register($events);
        }

        if ($config->get('telemetry.instrument.models', true)) {
            (new ModelInstrumentation($this->app))->register($events);
        }

        if ($config->get('telemetry.instrument.batches', true)) {
            (new BusInstrumentation($this->app))->register($events);
        }

        if ($config->get('telemetry.instrument.redis', false)) {
            // The package's own connections are ALWAYS ignored — self-
            // instrumentation would loop (telemetry writes generating
            // spans generating writes). An explicit ignore list is
            // UNIONED with these, never replaces them, so the documented
            // guarantee holds even when an operator adds their own.
            $ignored = Cast::stringList($config->get('telemetry.instrument.redis_ignore_connections', []));
            $ignored = array_values(array_unique([
                Cast::string($config->get('telemetry.stores.redis.connection'), 'default'),
                Cast::string($config->get('telemetry.otlp.spool.connection'), 'default'),
                ...$ignored,
            ]));

            $this->app->singleton(RedisInstrumentation::class);
            $this->app->make(RedisInstrumentation::class)->register($events, $ignored);
        }

        if ($config->get('telemetry.instrument.gates', true)) {
            // afterResolving, NOT booted(): the Gate is in Octane's flush
            // list, so a hook bound once to the boot-time instance is lost
            // after request #1. A resolving callback lives on the
            // container and re-arms every fresh Gate the worker resolves.
            // The WeakMap guards against arming the same instance twice
            // (afterResolving AND the boot-time arm can both see it).
            $armed = new \WeakMap;

            $arm = function (object $gate) use (&$armed): void {
                if (! method_exists($gate, 'after') || isset($armed[$gate])) {
                    return;
                }

                $armed[$gate] = true;
                $app = $this->app;

                $gate->after(function ($user, string $ability, $result) use ($app): void {
                    FailSafe::guard(function () use ($app, $ability, $result) {
                        $allowed = $result instanceof Response ? $result->allowed() : (bool) $result;
                        $telemetry = $app->make(TelemetryManager::class);

                        // Ability names are code identifiers — bounded.
                        $telemetry->counter('authorization.checks', 'Gate/policy checks by outcome')
                            ->inc(1, ['ability' => $ability, 'result' => $allowed ? 'allowed' : 'denied']);

                        $telemetry->tracer()->bumpStat('gate.check.count', 1);

                        if (! $allowed) {
                            $telemetry->tracer()->bumpStat('gate.denied.count', 1);
                        }
                    });
                });
            };

            $this->app->afterResolving(Gate::class, fn (object $gate) => FailSafe::guard(fn () => $arm($gate)));

            // Arm the instance that already exists at boot (FPM path, and
            // any Gate resolved before this callback registered).
            $this->app->booted(fn (Application $app) => FailSafe::guard(fn () => $app->resolved(Gate::class) ? $arm($app->make(Gate::class)) : null));
        }

        if ($config->get('telemetry.instrument.views', true)) {
            // After boot, so every view service provider has registered
            // its engines before we wrap them.
            $this->app->booted(function (Application $app) {
                (new ViewInstrumentation)->register($app);
            });
        }

        if ($config->get('telemetry.instrument.notifications', true)) {
            $this->app->singleton(NotificationInstrumentation::class);
            $this->app->make(NotificationInstrumentation::class)->register($events);
        }

        if ($config->get('telemetry.instrument.http_client', true) && class_exists(RequestSending::class)) {
            $this->app->singleton(HttpClientInstrumentation::class);
            $this->app->make(HttpClientInstrumentation::class)->register($events);
        }

        if ($config->get('telemetry.instrument.exceptions', true)) {
            $this->registerExceptionReporting();
        }

        if ($config->get('telemetry.instrument.pennant', true) && class_exists(FeatureRetrieved::class)) {
            $this->app->singleton(PennantInstrumentation::class);
            $this->app->make(PennantInstrumentation::class)->register($events);
        }

        if ($config->get('telemetry.instrument.horizon', true) && class_exists(SupervisorLooped::class)) {
            $this->app->singleton(HorizonInstrumentation::class);
            $this->app->make(HorizonInstrumentation::class)->register($events);
        }

        if ($config->get('telemetry.instrument.reverb', true) && class_exists(MessageSent::class)) {
            $this->app->singleton(ReverbInstrumentation::class);
            $this->app->make(ReverbInstrumentation::class)->register($events);
        }

        if ($config->get('telemetry.instrument.livewire', true) && class_exists(Livewire::class)) {
            Livewire::componentHook(LivewireInstrumentation::class);
        }

        if ($config->get('telemetry.instrument.broadcasting', true)) {
            (new BroadcastingInstrumentation)->register($this->app);
        }

        if ($config->get('telemetry.instrument.filesystem', true)) {
            (new FilesystemInstrumentation)->register($this->app);
        }
    }

    /**
     * Count every reported exception — including HANDLED ones that
     * report() swallows, which span instrumentation alone never sees —
     * and annotate the active span without failing it.
     */
    private function registerExceptionReporting(): void
    {
        $this->callAfterResolving(
            ExceptionHandler::class,
            function (object $handler) {
                if (! method_exists($handler, 'reportable')) {
                    return;
                }

                $app = $this->app;

                $handler->reportable(static function (\Throwable $e) use ($app): void {
                    FailSafe::guard(function () use ($app, $e) {
                        $telemetry = $app->make(TelemetryManager::class);
                        $config = $app->make('config');

                        // Bounded metric: rate/alerting by class.
                        $telemetry->counter('exceptions.reported', 'Exceptions passed to report()')
                            ->inc(1, ['exception' => $e::class]);

                        $attributes = ExceptionAttributes::from(
                            $e,
                            $app->basePath(),
                            (bool) $config->get('telemetry.instrument.exception_source', false),
                        );

                        // Who hit it: the authenticated user, so issue
                        // tooling can say "affects N users" (Sentry-style).
                        // Guarded — auth may be unbootable mid-failure.
                        $userId = FailSafe::guard(static function () use ($app): ?string {
                            $user = $app->make('auth')->user();

                            return $user !== null ? Cast::string($user->getAuthIdentifier()) : null;
                        });

                        if (is_string($userId) && $userId !== '') {
                            $attributes['enduser.id'] = $userId;
                        }

                        // Trace waterfall: annotate the active span WITHOUT
                        // failing it (report() may be a handled + recovered
                        // path). Deduped so a failed job isn't recorded twice.
                        $telemetry->currentSpan()?->noteException($e, fail: false);

                        // Issues feed: a structured, searchable error record
                        // (OTLP log → Loki) with a fingerprint — captured even
                        // out of a trace or when the trace is sampled away.
                        $span = $telemetry->currentSpan();
                        $telemetry->recordEvent(new TelemetryEvent(
                            name: 'exception',
                            timeUnixNano: (int) (microtime(true) * 1e9),
                            attributes: $telemetry->contextAttributes() + $attributes,
                            traceId: $span->traceId ?? $telemetry->traceId(),
                            spanId: $span?->spanId,
                            severityNumber: 17, // ERROR
                            severityText: 'ERROR',
                        ));
                    });
                });
            },
        );
    }

    private function registerSystemMetricsProvider(): void
    {
        $config = $this->app->make('config');

        if (! $config->get('telemetry.providers.system.enabled')) {
            return;
        }

        if (! class_exists(SystemMetrics::class)) {
            return;
        }

        $this->app->make(TelemetryManager::class)->provider(new SystemMetricsProvider(
            cpuInterval: Cast::float($config->get('telemetry.providers.system.cpu_interval'), 0.1),
        ));
    }

    private function registerOctaneReset(): void
    {
        if (! class_exists(RequestReceived::class)) {
            return;
        }

        // On a fresh Octane request, drop any trace context AND any
        // half-open instrumentation state a prior request left behind
        // (a request that died mid-HTTP-call or mid-transaction). Without
        // this the singleton instrumentations leak worker memory and can
        // mis-parent the next request's spans.
        $reset = function (): void {
            FailSafe::guard(function () {
                $this->app->make(TelemetryManager::class)->resetContext();

                foreach ($this->statefulInstrumentations() as $abstract) {
                    if ($this->app->resolved($abstract)) {
                        $instance = $this->app->make($abstract);

                        if ($instance instanceof ManagesRequestState) {
                            $instance->flushRequestState();
                        }
                    }
                }
            });
        };

        $dispatcher = $this->app->make(Dispatcher::class);
        $dispatcher->listen(RequestReceived::class, $reset);

        // RoadRunner/FrankenPHP/Swoole all surface as Octane; the tick
        // worker (queue-less scheduling) resets on the same signal.
        if (class_exists(TickReceived::class)) {
            $dispatcher->listen(TickReceived::class, $reset);
        }
    }

    /**
     * @return list<class-string>
     */
    private function statefulInstrumentations(): array
    {
        // Deliberately NOT QueueInstrumentation: a job's lifecycle is
        // bounded by JobProcessed/JobFailed, not the HTTP request/tick
        // boundary. Resetting it here would wipe an in-flight job span
        // when a job runs inside an Octane worker (dispatchSync, task
        // workers). It self-cleans on job-completion events.
        return [
            CacheInstrumentation::class,
            HttpClientInstrumentation::class,
            MailInstrumentation::class,
            NotificationInstrumentation::class,
            TransactionInstrumentation::class,
            CommandInstrumentation::class,
        ];
    }
}
