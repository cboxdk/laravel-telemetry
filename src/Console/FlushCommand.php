<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Console;

use Cbox\Telemetry\Exporters\Otlp\OtlpTransport;
use Cbox\Telemetry\Exporters\Spool\Spool;
use Cbox\Telemetry\Exporters\Spool\SpoolShipper;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Illuminate\Console\Command;

/**
 * Pushes metrics from the shared store (plus observable gauges) to the
 * configured exporters, and drains the OTLP spool when enabled.
 *
 * Cron mode (fine for most sites — spans already export at terminate):
 *
 *     Schedule::command('telemetry:flush')->everyMinute()->onOneServer();
 *
 * Daemon mode (high traffic + spool: sub-second span shipping, merged
 * batches, one process under supervisor):
 *
 *     php artisan telemetry:flush --daemon --interval=1
 *
 * Prometheus scraping does not need this command.
 */
final class FlushCommand extends Command
{
    protected $signature = 'telemetry:flush
                            {--daemon : Keep running, shipping the spool every --interval seconds}
                            {--interval=1 : Seconds between spool ships in daemon mode}
                            {--metrics-interval=15 : Seconds between metric flushes in daemon mode}
                            {--max-batch=200 : Max spool entries merged into one OTLP request}
                            {--wipe : Wipe the metric store after exporting (turns cumulative into delta-per-flush — leave off unless you know you need it)}';

    protected $description = 'Export metrics and drain the OTLP spool — once (cron) or as a daemon';

    private bool $shouldStop = false;

    public function handle(TelemetryManager $telemetry): int
    {
        if (! $telemetry->enabled()) {
            $this->components->warn('Telemetry is disabled (TELEMETRY_ENABLED=false); nothing to flush.');

            return self::SUCCESS;
        }

        $shipper = $this->spoolShipper();

        if ($this->option('daemon')) {
            return $this->daemon($telemetry, $shipper);
        }

        $count = $telemetry->flushMetrics();
        $telemetry->flush();

        $this->components->info(sprintf(
            'Flushed %d metric %s to %d exporter(s).',
            $count,
            $count === 1 ? 'family' : 'families',
            count($telemetry->exporters()),
        ));

        if ($shipper !== null) {
            $result = $shipper->ship((int) $this->option('max-batch'));

            $this->components->info(sprintf(
                'Shipped %d spooled payload(s).%s',
                $result['shipped'],
                $result['requeued'] > 0 ? " {$result['requeued']} requeued — endpoint unreachable." : '',
            ));
        }

        if ($this->option('wipe')) {
            $telemetry->registry()->store()->wipe();

            $this->components->info('Metric store wiped.');
        }

        return self::SUCCESS;
    }

    private function daemon(TelemetryManager $telemetry, ?SpoolShipper $shipper): int
    {
        $interval = max(1, (int) $this->option('interval'));
        $metricsInterval = max($interval, (int) $this->option('metrics-interval'));
        $maxBatch = max(1, (int) $this->option('max-batch'));

        $this->trapSignals();

        $this->components->info(sprintf(
            'Shipping %severy %ds, metrics every %ds. Ctrl+C to stop.',
            $shipper !== null ? 'the spool ' : '',
            $interval,
            $metricsInterval,
        ));

        $lastMetricsFlush = 0.0;

        while (! $this->shouldStop) {
            if ($shipper !== null) {
                FailSafe::guard(fn () => $shipper->ship($maxBatch));
            }

            if (microtime(true) - $lastMetricsFlush >= $metricsInterval) {
                FailSafe::guard(fn () => $telemetry->flushMetrics());
                FailSafe::guard(fn () => $telemetry->flush());

                $lastMetricsFlush = microtime(true);
            }

            sleep($interval);
        }

        // Drain what arrived during shutdown before handing back to
        // supervisor — nothing sits in the spool across restarts.
        if ($shipper !== null) {
            FailSafe::guard(fn () => $shipper->ship($maxBatch));
        }

        $this->components->info('Telemetry flush daemon stopped.');

        return self::SUCCESS;
    }

    private function spoolShipper(): ?SpoolShipper
    {
        if (! $this->laravel->make('config')->get('telemetry.otlp.spool.enabled', false)) {
            return null;
        }

        $spool = $this->laravel->make(Spool::class);
        $transport = $this->laravel->make(OtlpTransport::class);

        return new SpoolShipper($spool, fn (string $path, array $payload): bool => $transport->post($path, $payload)->success);
    }

    private function trapSignals(): void
    {
        if (! extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->shouldStop = true;
            });
        }
    }
}
