<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Console;

use Cbox\Telemetry\TelemetryManager;
use Illuminate\Console\Command;

/**
 * Pushes metrics from the shared store (plus observable gauges) to the
 * configured exporters. Schedule it when using OTLP metrics:
 *
 *     Schedule::command('telemetry:flush')->everyMinute();
 *
 * Prometheus scraping does not need this command.
 */
final class FlushCommand extends Command
{
    protected $signature = 'telemetry:flush
                            {--wipe : Wipe the metric store after exporting (turns cumulative into delta-per-flush — leave off unless you know you need it)}';

    protected $description = 'Export metrics and any buffered spans/events to the configured exporters';

    public function handle(TelemetryManager $telemetry): int
    {
        if (! $telemetry->enabled()) {
            $this->components->warn('Telemetry is disabled (TELEMETRY_ENABLED=false); nothing to flush.');

            return self::SUCCESS;
        }

        $families = $telemetry->collect();

        $telemetry->flushMetrics();
        $telemetry->flush();

        $this->components->info(sprintf(
            'Flushed %d metric %s to %d exporter(s).',
            count($families),
            count($families) === 1 ? 'family' : 'families',
            count($telemetry->exporters()),
        ));

        if ($this->option('wipe')) {
            $telemetry->registry()->store()->wipe();

            $this->components->info('Metric store wiped.');
        }

        return self::SUCCESS;
    }
}
