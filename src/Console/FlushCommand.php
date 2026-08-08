<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Console;

use Cbox\Telemetry\Exporters\Otlp\OtlpTransport;
use Cbox\Telemetry\Exporters\Spool\ShipResult;
use Cbox\Telemetry\Exporters\Spool\Spool;
use Cbox\Telemetry\Exporters\Spool\SpoolShipper;
use Cbox\Telemetry\Support\ExportOutcome;
use Cbox\Telemetry\Support\ExportReport;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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
 *
 * A batch the backend refused exits non-zero and logs at error level.
 * This runs from cron, where an exit code is the only thing anyone
 * watches — reporting "flushed" for data that was rejected is how a
 * broken pipeline stays broken for weeks.
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

    /**
     * The last failure the daemon reported for each stream (metrics,
     * spans, the spool), so an endpoint that is down for an hour writes
     * one log line per distinct failure instead of one per tick. Keyed
     * per stream: metrics failing while spans land must not read as a
     * recovery, and then as a fresh failure a tick later.
     *
     * @var array<string, string>
     */
    private array $lastReportedFailure = [];

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

        // Guarded like the daemon loop (below) — a failure here must
        // surface as a clean error + non-zero exit for cron/monitoring
        // to catch, never an uncaught exception dumped to the console.
        $report = FailSafe::guard(fn () => $telemetry->flushMetrics());

        if ($report === null) {
            $this->components->error('Failed to flush metrics — see the configured exception handler for details.');

            return self::FAILURE;
        }

        $healthy = $this->reportMetrics($report);

        // Spans and events buffered by this process (the command's own
        // instrumentation). Rarely anything, but a rejection here is a
        // rejection all the same.
        $spans = FailSafe::guard(fn () => $telemetry->flush());

        if ($spans === null) {
            $this->components->error('Failed to flush spans and events — see the configured exception handler for details.');

            $healthy = false;
        } elseif (! $spans->successful()) {
            $this->reportFailures('Spans and events were not accepted: '.$spans->summary(), $spans->problems());

            $healthy = false;
        }

        if ($shipper !== null) {
            $result = FailSafe::guard(fn () => $shipper->ship((int) $this->option('max-batch')));

            if ($result === null) {
                $this->components->error('Failed to ship the spool — see the configured exception handler for details.');

                return self::FAILURE;
            }

            $healthy = $this->reportSpool($result) && $healthy;
        }

        if ($this->option('wipe')) {
            $wiped = FailSafe::guard(function () use ($telemetry): true {
                $telemetry->registry()->store()->wipe();

                return true;
            });

            if ($wiped === null) {
                $this->components->error('Failed to wipe the metric store.');

                return self::FAILURE;
            }

            $this->components->info('Metric store wiped.');
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
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
                $this->watchSpool(FailSafe::guard(fn () => $shipper->ship($maxBatch)));
            }

            if (microtime(true) - $lastMetricsFlush >= $metricsInterval) {
                $this->watchExport(FailSafe::guard(fn () => $telemetry->flushMetrics()), 'metrics');
                $this->watchExport(FailSafe::guard(fn () => $telemetry->flush()), 'spans and events');

                $lastMetricsFlush = microtime(true);
            }

            sleep($interval);
        }

        // One last drain of what arrived during shutdown. Anything the
        // endpoint still rejects stays durably in Redis and the next
        // daemon start picks it up — the spool survives restarts.
        if ($shipper !== null) {
            $this->watchSpool(FailSafe::guard(fn () => $shipper->ship($maxBatch)));
        }

        $this->components->info('Telemetry flush daemon stopped.');

        return self::SUCCESS;
    }

    /*
    |--------------------------------------------------------------------------
    | Reporting
    |--------------------------------------------------------------------------
    */

    /**
     * @return bool whether every exporter took the metrics
     */
    private function reportMetrics(ExportReport $report): bool
    {
        $families = sprintf('%d metric %s', $report->items, $report->items === 1 ? 'family' : 'families');

        if ($report->items === 0) {
            $this->components->info('No metrics to flush.');

            return true;
        }

        if ($report->attempted() === 0) {
            $this->components->info("Collected {$families}; no exporter handles metrics (Prometheus scrape only).");

            return true;
        }

        if ($report->successful()) {
            $this->components->info(sprintf('Flushed %s to %d exporter(s).', $families, $report->attempted()));

            return true;
        }

        $this->reportFailures(
            $report->failures() === []
                ? sprintf('Flushed %s, but the backend rejected %d data point(s).', $families, $report->rejected())
                : "Flushed {$families}: ".$report->summary(),
            $report->problems(),
        );

        return false;
    }

    /**
     * @return bool whether the drain lost or held back nothing
     */
    private function reportSpool(ShipResult $result): bool
    {
        if ($result->successful()) {
            $this->components->info(sprintf('Shipped %d spooled payload(s).', $result->shipped));

            return true;
        }

        $this->reportFailures(sprintf(
            'Shipped %d spooled payload(s); %d requeued, %d dropped (permanently rejected — that data is gone).',
            $result->shipped,
            $result->requeued,
            $result->dropped,
        ), $result->failures);

        return false;
    }

    /**
     * Print the headline and every reason, and log the same — under cron
     * nobody reads stdout, and the log is where an alert can see it.
     *
     * @param  list<ExportOutcome>  $failures
     */
    private function reportFailures(string $headline, array $failures): void
    {
        $this->components->error($headline);

        foreach ($failures as $failure) {
            $this->components->twoColumnDetail($failure->exporter, '<fg=red>'.$failure->describe().'</>');
        }

        Log::error("telemetry:flush — {$headline}", [
            'failures' => array_map(fn (ExportOutcome $failure): array => [
                'exporter' => $failure->exporter,
                'status' => $failure->status->value,
                'reason' => $failure->reason,
            ], $failures),
        ]);
    }

    /**
     * Daemon variant: same reporting, but only when the failure changed.
     * A collector that is down for an hour must not write 3,600 identical
     * log lines.
     */
    private function watchExport(?ExportReport $report, string $what): void
    {
        if ($report === null) {
            $this->reportOnce($what, "Failed to flush {$what}", 'threw', []);

            return;
        }

        if ($report->successful()) {
            $this->recovered($what);

            return;
        }

        $this->reportOnce(
            $what,
            ucfirst($what).' were not accepted: '.$report->summary(),
            $this->signature($report->problems()),
            $report->problems(),
        );
    }

    private function watchSpool(?ShipResult $result): void
    {
        if ($result === null) {
            $this->reportOnce('the spool', 'Failed to ship the spool', 'threw', []);

            return;
        }

        if ($result->successful()) {
            $this->recovered('the spool');

            return;
        }

        $this->reportOnce(
            'the spool',
            sprintf('Spool drain failed — %d requeued, %d dropped.', $result->requeued, $result->dropped),
            $this->signature($result->failures),
            $result->failures,
        );
    }

    /**
     * @param  list<ExportOutcome>  $failures
     */
    private function reportOnce(string $what, string $headline, string $signature, array $failures): void
    {
        if (($this->lastReportedFailure[$what] ?? null) === $signature) {
            return;
        }

        $this->lastReportedFailure[$what] = $signature;

        $this->reportFailures($headline, $failures);
    }

    private function recovered(string $what): void
    {
        if (! isset($this->lastReportedFailure[$what])) {
            return;
        }

        unset($this->lastReportedFailure[$what]);

        $this->components->info('Exports are landing again ('.$what.').');
        Log::info('telemetry:flush — exports are landing again', ['signal' => $what]);
    }

    /**
     * @param  list<ExportOutcome>  $failures
     */
    private function signature(array $failures): string
    {
        return implode('|', array_map(
            fn (ExportOutcome $failure): string => $failure->exporter.':'.$failure->status->value.':'.($failure->reason ?? ''),
            $failures,
        ));
    }

    private function spoolShipper(): ?SpoolShipper
    {
        if (! $this->laravel->make('config')->get('telemetry.otlp.spool.enabled', false)) {
            return null;
        }

        $spool = $this->laravel->make(Spool::class);
        $transport = $this->laravel->make(OtlpTransport::class);

        return new SpoolShipper($spool, fn (string $path, array $payload) => $transport->post($path, $payload));
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
