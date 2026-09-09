<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Console;

use Cbox\SystemMetrics\DTO\Metrics\Cpu\CpuSnapshot;
use Cbox\SystemMetrics\ProcessMetrics;
use Cbox\SystemMetrics\SystemMetrics;
use Cbox\Telemetry\Support\Cast;
use Cbox\Telemetry\Support\ExportReport;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The optional node_exporter analog: samples host metrics (CPU via a
 * proper between-tick delta, memory, load, disk, network) and any
 * configured foreign processes (Reverb, Horizon, queue workers) as PUSH
 * gauges into the shared store.
 *
 * Two modes, per the package's no-required-daemon principle:
 *
 *     Schedule::command('telemetry:monitor --once')->everyMinute();  // cron mode
 *     php artisan telemetry:monitor --interval=15                    // daemon under supervisor
 *
 * When running the monitor, set TELEMETRY_SYSTEM_METRICS=false so
 * scrape-time evaluation doesn't duplicate the same gauges (and scrapes
 * stop paying the CPU sampling interval).
 */
final class MonitorCommand extends Command
{
    protected $signature = 'telemetry:monitor
                            {--interval=15 : Seconds between samples in daemon mode}
                            {--once : Sample once and exit (cron/scheduler mode)}';

    protected $description = 'Sample host and process metrics into the telemetry store (node_exporter analog)';

    /** @var CpuSnapshot|null */
    private ?object $previousCpu = null;

    /** The last failure reported in daemon mode; '' means healthy. */
    private ?string $lastReported = null;

    public function handle(TelemetryManager $telemetry): int
    {
        if (! $telemetry->enabled()) {
            $this->components->warn('Telemetry is disabled; nothing to monitor.');

            return self::SUCCESS;
        }

        if (! class_exists(SystemMetrics::class)) {
            $this->components->error('telemetry:monitor requires cboxdk/system-metrics. composer require cboxdk/system-metrics');

            return self::FAILURE;
        }

        $interval = max(1, (int) $this->option('interval'));

        $this->components->info($this->option('once')
            ? 'Sampling host & process metrics once.'
            : "Monitoring host & process metrics every {$interval}s. Ctrl+C to stop.");

        do {
            FailSafe::guard(fn () => $this->sampleHost($telemetry));
            FailSafe::guard(fn () => $this->sampleProcesses($telemetry));

            $report = FailSafe::guard(fn (): ExportReport => $telemetry->flush());

            if ($this->option('once')) {
                // Read from cron, where the exit code is the whole report.
                // Sampling a host and then failing to ship it is a failure,
                // and this returned SUCCESS for one until now.
                return $this->reportOneShot($report);
            }

            $this->watch($report);

            sleep($interval);
        } while (true);
    }

    /**
     * A single run answers with its exit code.
     */
    private function reportOneShot(?ExportReport $report): int
    {
        if ($report === null) {
            $this->components->error('Failed to flush the sampled metrics.');

            return self::FAILURE;
        }

        if ($report->successful()) {
            return self::SUCCESS;
        }

        $this->components->error('The sampled metrics were not accepted: '.$report->summary());

        foreach ($report->problems() as $problem) {
            $this->components->twoColumnDetail($problem->exporter, '<fg=red>'.$problem->describe().'</>');
        }

        Log::error('telemetry:monitor — the sampled metrics were not accepted', [
            'summary' => $report->summary(),
        ]);

        return self::FAILURE;
    }

    /**
     * The daemon answers to a log, and only when something changed.
     *
     * A collector that is down for an hour must not write 3,600 identical
     * lines. FlushCommand solves the same problem the same way; the two are
     * not shared yet because its copy is interleaved with spool-specific
     * reporting, and untangling a command released hours ago is a worse
     * trade than these few lines.
     */
    private function watch(?ExportReport $report): void
    {
        $signature = match (true) {
            $report === null => 'threw',
            $report->successful() => '',
            default => $report->summary(),
        };

        if ($signature === $this->lastReported) {
            return;
        }

        $this->lastReported = $signature;

        if ($signature === '') {
            $this->components->info('Metrics are being accepted again.');
            Log::info('telemetry:monitor — metrics are being accepted again');

            return;
        }

        $headline = $report === null
            ? 'Failed to flush the sampled metrics.'
            : 'The sampled metrics were not accepted: '.$report->summary();

        $this->components->error($headline);
        Log::error("telemetry:monitor — {$headline}");
    }

    private function sampleHost(TelemetryManager $telemetry): void
    {
        $metrics = SystemMetrics::class;

        if (($cpuPercentage = $this->sampleCpuPercentage()) !== null) {
            $telemetry->gauge('system.cpu.utilization', description: 'CPU busy fraction (0-1)', unit: '1')
                ->set($cpuPercentage / 100);
        }

        if (($memory = $metrics::memory()->getValueOr(null)) !== null) {
            $gauge = $telemetry->gauge('system.memory.usage', description: 'Memory in use by state', unit: 'By');
            $gauge->set((float) $memory->usedBytes, ['state' => 'used']);
            $gauge->set((float) $memory->freeBytes, ['state' => 'free']);
            $gauge->set((float) $memory->cachedBytes, ['state' => 'cached']);

            $telemetry->gauge('system.memory.utilization', description: 'Fraction of memory in use (0-1)', unit: '1')
                ->set($memory->usedPercentage() / 100, ['state' => 'used']);
        }

        if (($load = $metrics::loadAverage()->getValueOr(null)) !== null) {
            $gauge = $telemetry->gauge('system.cpu.load_average', description: 'System load average', unit: '1');
            $gauge->set($load->oneMinute, ['period' => '1m']);
            $gauge->set($load->fiveMinutes, ['period' => '5m']);
            $gauge->set($load->fifteenMinutes, ['period' => '15m']);
        }

        if (($storage = $metrics::storage()->getValueOr(null)) !== null) {
            $gauge = $telemetry->gauge('system.filesystem.usage', description: 'Filesystem bytes by state', unit: 'By');
            $gauge->set((float) $storage->usedBytes(), ['state' => 'used']);
            $gauge->set((float) $storage->availableBytes(), ['state' => 'free']);
        }

        if (($network = $metrics::network()->getValueOr(null)) !== null) {
            $gauge = $telemetry->gauge('system.network.io', description: 'Cumulative network bytes by direction (use rate())', unit: 'By');
            $gauge->set((float) $network->totalBytesReceived(), ['direction' => 'receive']);
            $gauge->set((float) $network->totalBytesSent(), ['direction' => 'transmit']);
        }
    }

    /**
     * CPU busy percentage, by whichever method the mode allows.
     *
     * The daemon deltas between its own ticks — the honest measure, and no
     * blocking sleep. Cron mode has no next tick to delta against: the process
     * exits, `$previousCpu` dies with it, and every `--once` run is therefore
     * a first sample. It reported no CPU at all, silently, in the mode the
     * README recommends for hosts without a supervisor. So it pays for one
     * short blocking sample instead — the same one the scrape-time provider
     * takes, sized by the same config key.
     */
    private function sampleCpuPercentage(): ?float
    {
        if (! $this->option('once')) {
            $cpu = SystemMetrics::cpu()->getValueOr(null);

            if (! $cpu instanceof CpuSnapshot) {
                return null;
            }

            $previous = $this->previousCpu;
            $this->previousCpu = $cpu;

            return $previous === null
                ? null
                : $cpu::calculateDelta($previous, $cpu)->usagePercentage();
        }

        $interval = Cast::float(
            $this->laravel->make('config')->get('telemetry.providers.system.cpu_interval'),
            0.1,
        );

        return SystemMetrics::cpuUsage($interval)->getValueOr(null)?->usagePercentage();
    }

    /**
     * Foreign long-running processes (Reverb, Horizon, workers) found by
     * pgrep pattern — the memory-leak view for processes that never run
     * app code between units of work.
     */
    private function sampleProcesses(TelemetryManager $telemetry): void
    {
        /** @var array<string, string> $processes */
        $processes = config('telemetry.monitor.processes', []);

        foreach ($processes as $name => $pattern) {
            $pids = $this->pgrep($pattern);

            $telemetry->gauge('process.count', description: 'Matched processes per monitored group', unit: '{processes}')
                ->set((float) count($pids), ['process' => $name]);

            if ($pids === []) {
                continue;
            }

            $totalRss = 0;

            foreach ($pids as $pid) {
                $snapshot = ProcessMetrics::snapshot($pid)->getValueOr(null);

                if ($snapshot !== null) {
                    $totalRss += $snapshot->resources->memoryRssBytes;
                }
            }

            $telemetry->gauge('process.memory.rss', description: 'Aggregate RSS per monitored process group', unit: 'By')
                ->set((float) $totalRss, ['process' => $name]);
        }
    }

    /**
     * @return list<int>
     */
    private function pgrep(string $pattern): array
    {
        $output = [];

        exec('pgrep -f '.escapeshellarg($pattern).' 2>/dev/null', $output);

        $self = getmypid();

        return array_values(array_filter(
            array_map(intval(...), $output),
            fn (int $pid) => $pid > 0 && $pid !== $self,
        ));
    }
}
