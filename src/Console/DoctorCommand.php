<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Console;

use Cbox\Telemetry\Contracts\MetricStore;
use Cbox\Telemetry\Exporters\Otlp\OtlpTransport;
use Cbox\Telemetry\Exporters\Spool\Spool;
use Cbox\Telemetry\Metrics\MetricDefinition;
use Cbox\Telemetry\Metrics\MetricType;
use Cbox\Telemetry\Support\Cast;
use Cbox\Telemetry\TelemetryManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * Verifies the telemetry setup end to end: store round trip, exporter
 * reachability and configuration sanity. Run it after install and from
 * deploy pipelines.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'telemetry:doctor';

    protected $description = 'Verify the telemetry configuration, store and exporters';

    public function handle(TelemetryManager $telemetry, MetricStore $store, Spool $spool): int
    {
        if (! $telemetry->enabled()) {
            $this->components->warn('Telemetry is DISABLED (TELEMETRY_ENABLED=false). Nothing else to check.');

            return self::SUCCESS;
        }

        $this->components->info('Telemetry is enabled.');

        $healthy = $this->checkStore($store);
        $this->checkCacheCollision();
        $this->checkProfiling();
        $healthy = $this->checkPrometheus() && $healthy;
        $healthy = $this->checkOtlp() && $healthy;
        $healthy = $this->checkSpool($spool) && $healthy;

        if ($healthy) {
            $this->components->info('All checks passed.');
        } else {
            $this->components->error('One or more checks failed — see above.');
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    private function checkStore(MetricStore $store): bool
    {
        $driver = Cast::string(config('telemetry.store'), 'redis');

        try {
            $definition = new MetricDefinition('telemetry.doctor.heartbeat', MetricType::Gauge, 'telemetry:doctor round-trip check', 's');

            $store->setGauge($definition, [], microtime(true));

            foreach ($store->collect() as $family) {
                if ($family->name() === 'telemetry.doctor.heartbeat') {
                    $this->components->twoColumnDetail("Metric store [{$driver}]", '<fg=green>OK — write/read round trip</>');

                    return true;
                }
            }

            $this->components->twoColumnDetail("Metric store [{$driver}]", '<fg=red>FAILED — wrote a gauge but could not read it back</>');

            return false;
        } catch (Throwable $e) {
            $this->components->twoColumnDetail("Metric store [{$driver}]", '<fg=red>FAILED — '.$e->getMessage().'</>');

            return false;
        }
    }

    private function checkPrometheus(): bool
    {
        if (! config('telemetry.prometheus.enabled')) {
            $this->components->twoColumnDetail('Prometheus', 'disabled');

            return true;
        }

        /** @var array<string, array{path?: string}> $endpoints */
        $endpoints = config('telemetry.prometheus.endpoints', []);
        $paths = collect($endpoints)->map(fn ($endpoint) => '/'.ltrim($endpoint['path'] ?? '', '/'))->implode(', ');

        /** @var list<string> $allowedIps */
        $allowedIps = config('telemetry.prometheus.allowed_ips', []);
        $hasToken = Cast::string(config('telemetry.prometheus.token')) !== '';

        if ($allowedIps !== []) {
            $this->components->twoColumnDetail("Prometheus [{$paths}]", '<fg=green>OK — allowlist: '.implode(', ', $allowedIps).'</>');
        } elseif ($hasToken) {
            $this->components->twoColumnDetail("Prometheus [{$paths}]", '<fg=green>OK — bearer token configured</>');
        } elseif (app()->environment('local', 'testing')) {
            $this->components->twoColumnDetail("Prometheus [{$paths}]", '<fg=yellow>OPEN — no allowlist or token, but running in '.app()->environment().'</>');
        } else {
            $this->components->twoColumnDetail("Prometheus [{$paths}]", '<fg=red>CLOSED — no TELEMETRY_ALLOWED_IPS or TELEMETRY_PROMETHEUS_TOKEN set outside local/testing; every scrape will 403</>');
        }

        return true;
    }

    /**
     * `php artisan cache:clear` flushes the CURRENT cache store's entire
     * backing storage — `RedisStore::flush()` is a raw `FLUSHDB` (not
     * prefix-scoped), and `apcu_clear_cache()` wipes the whole shared
     * memory segment for every worker on the machine. Neither is aware
     * of telemetry's key prefix. If the metric store shares the same
     * Redis database or the same APCu segment as the app's cache, a
     * routine cache:clear silently destroys every metric. This is
     * informational, not a failure — the setup still works, it's just
     * one `cache:clear` away from an empty dashboard.
     */
    private function checkCacheCollision(): void
    {
        $store = Cast::string(config('telemetry.store'), 'redis');
        $cacheStore = Cast::string(config('cache.default'));
        $cacheDriver = Cast::string(config("cache.stores.{$cacheStore}.driver"));

        if ($store === 'apcu' && $cacheDriver === 'apcu') {
            $this->components->twoColumnDetail(
                'Cache collision',
                '<fg=yellow>apcu_clear_cache() (via `cache:clear`) wipes the WHOLE APCu segment, telemetry included — no prefix can protect it</>',
            );

            return;
        }

        if ($store === 'redis' && $cacheDriver === 'redis') {
            $telemetryConnection = Cast::string(config('telemetry.stores.redis.connection'), 'default');
            $cacheConnection = Cast::string(config("cache.stores.{$cacheStore}.connection"), 'default');

            if (config("database.redis.{$telemetryConnection}") === config("database.redis.{$cacheConnection}")) {
                $this->components->twoColumnDetail(
                    'Cache collision',
                    "<fg=yellow>telemetry and the [{$cacheStore}] cache store share the same Redis database — `cache:clear` runs FLUSHDB and wipes all metrics. Use a separate TELEMETRY_REDIS_CONNECTION</>",
                );
            }
        }
    }

    private function checkProfiling(): void
    {
        if (! config('telemetry.instrument.profiling', true)) {
            $this->components->twoColumnDetail('CPU profiling', 'disabled');

            return;
        }

        if (extension_loaded('excimer')) {
            $this->components->twoColumnDetail('CPU profiling', '<fg=green>OK — ext-excimer loaded</>');

            return;
        }

        $this->components->twoColumnDetail('CPU profiling', 'off — ext-excimer not installed (optional)');
    }

    private function checkOtlp(): bool
    {
        /** @var list<string> $exporters */
        $exporters = config('telemetry.exporters', []);

        if (! in_array('otlp', $exporters, true)) {
            $this->components->twoColumnDetail('OTLP', $exporters === [] ? 'no exporters configured (Prometheus scrape only)' : 'not configured');

            return true;
        }

        $endpoint = Cast::string(config('telemetry.otlp.endpoint'));

        $transport = new OtlpTransport(
            endpoint: $endpoint,
            headers: Cast::stringMap(config('telemetry.otlp.headers', [])),
            timeout: Cast::float(config('telemetry.otlp.timeout'), 3.0),
            connectTimeout: Cast::float(config('telemetry.otlp.connect_timeout'), 1.0),
        );

        $start = microtime(true);
        $result = $transport->post('/v1/traces', ['resourceSpans' => []]);
        $ms = (int) ((microtime(true) - $start) * 1000);

        if ($result->success) {
            $this->components->twoColumnDetail("OTLP [{$endpoint}]", "<fg=green>OK — accepted an empty batch in {$ms}ms</>");

            return true;
        }

        $this->components->twoColumnDetail("OTLP [{$endpoint}]", '<fg=red>FAILED — '.($result->reason ?? 'unknown').'</>');

        return false;
    }

    /**
     * The spool is drained EXCLUSIVELY by `telemetry:flush` (cron or
     * `--daemon`) — nothing else touches it. A depth close to
     * max_items is the one-invocation signal that the drain isn't
     * keeping up (or was never scheduled at all): past max_items the
     * spool silently drops its oldest entries with no other warning.
     */
    private function checkSpool(Spool $spool): bool
    {
        if (! config('telemetry.otlp.spool.enabled', false)) {
            $this->components->twoColumnDetail('OTLP spool', 'disabled');

            return true;
        }

        try {
            $depth = $spool->size();
        } catch (Throwable $e) {
            $this->components->twoColumnDetail('OTLP spool', '<fg=red>FAILED — '.$e->getMessage().'</>');

            return false;
        }

        $maxItems = Cast::int(config('telemetry.otlp.spool.max_items'), 20000);
        $ratio = $maxItems > 0 ? $depth / $maxItems : 0.0;

        if ($ratio >= 0.9) {
            $this->components->twoColumnDetail('OTLP spool', "<fg=red>{$depth}/{$maxItems} — near capacity, oldest entries are being dropped. Is `telemetry:flush --daemon` running, or scheduled via cron?</>");

            return false;
        }

        if ($ratio >= 0.5) {
            $this->components->twoColumnDetail('OTLP spool', "<fg=yellow>{$depth}/{$maxItems} — over half full. Verify telemetry:flush is actually running/scheduled.</>");

            return true;
        }

        $this->components->twoColumnDetail('OTLP spool', "<fg=green>OK — {$depth}/{$maxItems}</>");

        return true;
    }
}
