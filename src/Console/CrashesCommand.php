<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Console;

use Cbox\Telemetry\Contracts\NativeRuntime;
use Cbox\Telemetry\Native\CrashReporter;
use Cbox\Telemetry\Support\Cast;
use Cbox\Telemetry\TelemetryManager;
use Illuminate\Console\Command;

/**
 * Drains the crash records left behind by processes that died on a fatal
 * signal, reports them as events, and prints what it found.
 *
 * `telemetry:flush` already does this on its own schedule; this command
 * is for looking. Both consume — a record reported by one is not reported
 * again by the other, so running this by hand costs nothing but may mean
 * the crash shows up in the console instead of the next flush.
 */
final class CrashesCommand extends Command
{
    protected $signature = 'telemetry:crashes
                            {--max=32 : Maximum records to drain in one pass}';

    protected $description = 'Drain and report crash records from cboxdk/telemetry-native';

    public function handle(NativeRuntime $runtime, TelemetryManager $telemetry, CrashReporter $reporter): int
    {
        if (! $runtime->available()) {
            $this->components->warn('The cbox_telemetry extension is not loaded — no crash records are being recorded.');

            return self::SUCCESS;
        }

        $status = $runtime->status();
        $recorder = Cast::string($status['crash_recorder'] ?? null, 'unknown');

        if ($recorder !== 'armed') {
            $this->components->warn("Crash recorder: {$recorder} — records are not being written.");
        }

        $records = $reporter->drain(max(1, (int) $this->option('max')));

        if ($records === []) {
            $this->components->info('No crash records pending.');

            return self::SUCCESS;
        }

        foreach ($records as $record) {
            $this->components->twoColumnDetail(
                sprintf(
                    '<fg=red>%s</> pid %d',
                    Cast::string($record['crash.signal_name'] ?? null, 'UNKNOWN'),
                    Cast::int($record['crash.pid'] ?? null),
                ),
                sprintf(
                    '%s%s',
                    Cast::string($record['crash.unit'] ?? null, 'other'),
                    isset($record['crash.operation']) ? ' in '.Cast::string($record['crash.operation']) : '',
                ),
            );
        }

        // Reported, not just printed: a record drained and dropped on the
        // floor is worse than one still sitting in the sink.
        $telemetry->flush();

        $this->components->info(sprintf('Reported %d crash record(s).', count($records)));

        return self::SUCCESS;
    }
}
