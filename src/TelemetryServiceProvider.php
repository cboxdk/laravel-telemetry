<?php

declare(strict_types=1);

namespace Cbox\Telemetry;

use Cbox\SystemMetrics\SystemMetrics;
use Cbox\Telemetry\Console\DashboardsCommand;
use Cbox\Telemetry\Console\DoctorCommand;
use Cbox\Telemetry\Console\FlushCommand;
use Cbox\Telemetry\Console\MonitorCommand;
use Cbox\Telemetry\Contracts\Exporter;
use Cbox\Telemetry\Contracts\MetricStore;
use Cbox\Telemetry\Exporters\NullExporter;
use Cbox\Telemetry\Exporters\Otlp\OtlpExporter;
use Cbox\Telemetry\Exporters\Otlp\OtlpSerializer;
use Cbox\Telemetry\Exporters\Otlp\OtlpTransport;
use Cbox\Telemetry\Exporters\Prometheus\PrometheusRenderer;
use Cbox\Telemetry\Http\Controllers\PrometheusController;
use Cbox\Telemetry\Http\Middleware\TraceRequest;
use Cbox\Telemetry\Instrumentation\CacheInstrumentation;
use Cbox\Telemetry\Instrumentation\CommandInstrumentation;
use Cbox\Telemetry\Instrumentation\HttpClientInstrumentation;
use Cbox\Telemetry\Instrumentation\MailInstrumentation;
use Cbox\Telemetry\Instrumentation\NotificationInstrumentation;
use Cbox\Telemetry\Instrumentation\QueryInstrumentation;
use Cbox\Telemetry\Instrumentation\QueueInstrumentation;
use Cbox\Telemetry\Instrumentation\ScheduleInstrumentation;
use Cbox\Telemetry\Logging\TelemetryLogHandler;
use Cbox\Telemetry\Metrics\Registry;
use Cbox\Telemetry\Metrics\Stores\ApcuMetricStore;
use Cbox\Telemetry\Metrics\Stores\ArrayMetricStore;
use Cbox\Telemetry\Metrics\Stores\BufferedMetricStore;
use Cbox\Telemetry\Metrics\Stores\NullMetricStore;
use Cbox\Telemetry\Metrics\Stores\RedisMetricStore;
use Cbox\Telemetry\Providers\SystemMetricsProvider;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\Tracing\Tracer;
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
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestReceived;
use Monolog\Level;
use Monolog\Logger;

class TelemetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/telemetry.php', 'telemetry');

        $this->app->singleton(MetricStore::class, fn (Application $app) => $this->buildStore($app));

        $this->app->singleton(Registry::class, function (Application $app) {
            /** @var list<float> $buckets */
            $buckets = $app->make('config')->get('telemetry.default_buckets', []);

            return new Registry($app->make(MetricStore::class), $buckets);
        });

        $this->app->singleton(Tracer::class, function (Application $app) {
            $config = $app->make('config');
            $enabled = (bool) $config->get('telemetry.enabled');

            $tracer = new Tracer(
                sampleRate: $enabled ? (float) $config->get('telemetry.traces.sample_rate', 1.0) : 0.0,
                maxBuffer: (int) $config->get('telemetry.traces.max_buffer', 5000),
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
                maxBufferedEvents: (int) $app->make('config')->get('telemetry.events.max_buffer', 5000),
                tailDetails: $app->make('config')->get('telemetry.traces.details.mode', 'always') === 'tail',
                slowRequestMs: (float) $app->make('config')->get('telemetry.traces.details.slow_request_ms', 1000),
                slowSpanMs: (float) $app->make('config')->get('telemetry.traces.details.slow_span_ms', 100),
            );

            foreach ($this->buildExporters($app) as $exporter) {
                $manager->addExporter($exporter);
            }

            return $manager;
        });

        $this->app->alias(TelemetryManager::class, 'telemetry');

        $this->app->singleton(PrometheusRenderer::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/telemetry.php' => config_path('telemetry.php'),
            ], 'telemetry-config');

            $this->commands([FlushCommand::class, DoctorCommand::class, DashboardsCommand::class, MonitorCommand::class]);
        }

        if (! $this->app->make('config')->get('telemetry.enabled')) {
            return;
        }

        $this->registerPrometheusRoutes();
        $this->registerRequestInstrumentation();
        $this->registerQueueInstrumentation();
        $this->registerQueryInstrumentation();
        $this->registerCommandInstrumentation();
        $this->registerScheduleInstrumentation();
        $this->registerEventInstrumentations();
        $this->registerSystemMetricsProvider();
        $this->registerOctaneReset();
        $this->registerHttpClientMacro();
        $this->registerAboutCommand();
        $this->registerLogDriver();
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
                        $app->make(TelemetryManager::class),
                        $config['level'] ?? Level::Debug,
                    ),
                ]);
            });
        });
    }

    /**
     * Http::withTraceparent() attaches the current W3C trace context to an
     * outbound request, so the downstream service continues the trace:
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
            $traceparent = $app->make(TelemetryManager::class)->traceparent();

            return $traceparent === null
                ? $this
                : $this->withHeaders(['traceparent' => $traceparent]);
        });
    }

    private function registerAboutCommand(): void
    {
        if (! class_exists(AboutCommand::class)) {
            return;
        }

        AboutCommand::add('Telemetry', fn () => [
            'Enabled' => config('telemetry.enabled') ? '<fg=green;options=bold>ENABLED</>' : '<fg=yellow;options=bold>DISABLED</>',
            'Metric Store' => (string) config('telemetry.store'),
            'Exporters' => implode(', ', (array) config('telemetry.exporters', [])) ?: 'none',
            'Prometheus' => config('telemetry.prometheus.enabled')
                ? collect((array) config('telemetry.prometheus.endpoints'))->map(fn ($e) => '/'.ltrim($e['path'] ?? '', '/'))->implode(', ')
                    .(config('telemetry.prometheus.allowed_ips') === [] ? ' <fg=yellow;options=bold>(OPEN — no IP allowlist)</>' : '')
                : 'off',
            'Trace Sample Rate' => (string) config('telemetry.traces.sample_rate'),
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

        $driver = (string) $config->get('telemetry.store', 'redis');

        $store = match ($driver) {
            'redis' => new RedisMetricStore(
                redis: $app->make(RedisFactory::class),
                connection: (string) $config->get('telemetry.stores.redis.connection', 'default'),
                prefix: (string) $config->get('telemetry.stores.redis.prefix', 'telemetry'),
            ),
            'apcu' => new ApcuMetricStore(
                prefix: (string) $config->get('telemetry.stores.apcu.prefix', 'telemetry'),
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
            'service.name' => (string) $config->get('telemetry.service.name', 'laravel'),
            'deployment.environment.name' => (string) $config->get('telemetry.service.environment', 'production'),
            'host.name' => (string) gethostname(),
            'telemetry.sdk.name' => 'cboxdk/laravel-telemetry',
            'telemetry.sdk.language' => 'php',
        ];

        if (is_string($namespace = $config->get('telemetry.service.namespace'))) {
            $resource['service.namespace'] = $namespace;
        }

        if (is_string($version = $config->get('telemetry.service.version'))) {
            $resource['service.version'] = $version;
        }

        if (is_string($deployment = $config->get('telemetry.service.deployment')) && $deployment !== '') {
            $resource['deployment.id'] = $deployment;
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
                $name === 'otlp' => new OtlpExporter(
                    new OtlpTransport(
                        endpoint: (string) $config->get('telemetry.otlp.endpoint'),
                        headers: (array) $config->get('telemetry.otlp.headers', []),
                        timeout: (float) $config->get('telemetry.otlp.timeout', 3.0),
                        connectTimeout: (float) $config->get('telemetry.otlp.connect_timeout', 1.0),
                        compress: (bool) $config->get('telemetry.otlp.compression', true),
                    ),
                    new OtlpSerializer($this->buildResource($app)),
                ),
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

        $this->app->make(QueryInstrumentation::class)->register(
            $this->app->make(Dispatcher::class),
            (float) $this->app->make('config')->get('telemetry.instrument.queries_min_duration', 0),
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
            $this->app->make(CacheInstrumentation::class)->register($events, $cacheCounters, $cacheSpans);
        }

        if ($config->get('telemetry.instrument.mail', true)) {
            $this->app->singleton(MailInstrumentation::class);
            $this->app->make(MailInstrumentation::class)->register($events);
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

                        $telemetry->counter('exceptions.reported', 'Exceptions passed to report()')
                            ->inc(1, ['exception' => $e::class]);

                        $telemetry->currentSpan()?->addEvent('exception', [
                            'exception.type' => $e::class,
                            'exception.message' => $e->getMessage(),
                        ]);
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
            cpuInterval: (float) $config->get('telemetry.providers.system.cpu_interval', 0.1),
        ));
    }

    private function registerOctaneReset(): void
    {
        if (! class_exists(RequestReceived::class)) {
            return;
        }

        $this->app->make(Dispatcher::class)->listen(
            RequestReceived::class,
            fn () => $this->app->make(TelemetryManager::class)->resetContext(),
        );
    }
}
