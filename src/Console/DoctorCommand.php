<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Console;

use Cbox\Telemetry\Contracts\MetricStore;
use Cbox\Telemetry\Exporters\Otlp\OtlpTransport;
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

    public function handle(TelemetryManager $telemetry, MetricStore $store): int
    {
        if (! $telemetry->enabled()) {
            $this->components->warn('Telemetry is DISABLED (TELEMETRY_ENABLED=false). Nothing else to check.');

            return self::SUCCESS;
        }

        $this->components->info('Telemetry is enabled.');

        $healthy = $this->checkStore($store);
        $healthy = $this->checkPrometheus() && $healthy;
        $healthy = $this->checkOtlp() && $healthy;

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

        if ($allowedIps === []) {
            $this->components->twoColumnDetail("Prometheus [{$paths}]", '<fg=yellow>OPEN — no IP allowlist (set TELEMETRY_ALLOWED_IPS in production)</>');
        } else {
            $this->components->twoColumnDetail("Prometheus [{$paths}]", '<fg=green>OK — allowlist: '.implode(', ', $allowedIps).'</>');
        }

        return true;
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
}
