<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Console;

use Cbox\Telemetry\Contracts\NativeRuntime;
use Cbox\Telemetry\Native\CrashReporter;
use Cbox\Telemetry\Support\Cast;
use Cbox\Telemetry\Support\FailSafe;
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
        if (! $telemetry->enabled()) {
            $this->components->warn('Telemetry is disabled (TELEMETRY_ENABLED=false); crash records are left where they are.');

            return self::SUCCESS;
        }

        if (! $runtime->available()) {
            $this->components->warn('The cbox_telemetry extension is not loaded — no crash records are being recorded.');

            return self::SUCCESS;
        }

        $status = $runtime->status();
        $recorder = Cast::string($status['crash_recorder'] ?? null, 'unknown');

        if ($recorder !== 'armed') {
            $this->components->warn("Crash recorder: {$recorder} — records are not being written.");
        }

        // Flush first, then drain no more than the event buffer can hold.
        // Otherwise a drain big enough to fill the buffer trips the
        // manager's own overflow flush, which swallows its report — and a
        // rejection there is a consumed crash record nobody hears about.
        $buffer = Cast::int(config('telemetry.events.max_buffer'), 5000);

        FailSafe::guard(static fn () => $telemetry->flush());

        $records = $reporter->drain(max(1, min((int) $this->option('max'), $buffer - 1)));

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
        $report = FailSafe::guard(static fn () => $telemetry->flush());

        // And the drain already consumed them, so a rejected batch is a
        // record that no longer exists anywhere. Without the spool there is
        // no retry either — say so, and exit non-zero, because under cron an
        // exit code is the only thing anyone reads.
        if ($report === null || ! $report->successful()) {
            $this->components->error(sprintf(
                '%d crash record(s) were drained but NOT accepted%s — that data is gone unless the OTLP spool is enabled.',
                count($records),
                $report === null ? '' : ': '.$report->summary(),
            ));

            return self::FAILURE;
        }

        $this->components->info(sprintf('Reported %d crash record(s).', count($records)));

        return self::SUCCESS;
    }
}
