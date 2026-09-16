<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Native;

use Cbox\Telemetry\Contracts\NativeRuntime;
use Cbox\Telemetry\Events\TelemetryEvent;
use Cbox\Telemetry\Support\Cast;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;

/**
 * Collects the crash records processes leave behind when they die below
 * the level PHP's exception handler can see — a segfault in an extension,
 * an abort in a native library, the OOM killer.
 *
 * Draining CONSUMES: a record handed over is removed from the sink. So
 * this runs in exactly one place by default (`telemetry:flush`), and it
 * runs somewhere a failure is visible — a crash record that is drained
 * and then dropped on the floor is worse than one still sitting on disk.
 *
 * Each record is emitted as a FATAL-severity event carrying the trace and
 * span id that were in flight when the process died, so the crash lands in
 * the same trace as the request that caused it.
 */
final class CrashReporter
{
    public function __construct(
        private readonly NativeRuntime $runtime,
        private readonly TelemetryManager $telemetry,
    ) {}

    /**
     * @return list<array<string, scalar|null>> the attributes of every record
     *                                          reported, for a caller that
     *                                          wants to print them too
     */
    public function drain(?int $max = null): array
    {
        // Draining DELETES. With telemetry disabled the manager buffers
        // nothing and exports nothing, so a drain would consume the one
        // artefact a dead process left behind and hand it to a sink that
        // throws it away. Leave the records on disk for a run that can
        // actually report them.
        if (! $this->telemetry->enabled()
            || ! $this->runtime->available()
            || ! Cast::bool(config('telemetry.native.crashes'), true)
        ) {
            return [];
        }

        $reported = FailSafe::guard(function () use ($max): array {
            $limit = $max ?? Cast::int(config('telemetry.native.crash_max'), 32);
            $reported = [];

            foreach ($this->runtime->drainCrashes(max(1, $limit)) as $record) {
                $reported[] = $this->report($record);
            }

            return $reported;
        });

        return $reported ?? [];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, scalar|null>
     */
    private function report(array $record): array
    {
        $signal = Cast::string($record['signal_name'] ?? null, 'UNKNOWN');
        $unit = Cast::string($record['unit'] ?? null, 'other');

        $attributes = array_filter([
            'crash.signal' => Cast::int($record['signal'] ?? null),
            'crash.signal_name' => $signal,
            'crash.si_code' => Cast::int($record['si_code'] ?? null),
            'crash.pid' => Cast::int($record['pid'] ?? null),
            'crash.unit' => $unit,
            'crash.unit_duration_ms' => round(Cast::int($record['unit_duration_ns'] ?? null) / 1_000_000, 3),
            // Where it was when it died. An operation still open at the time
            // is the most useful single fact in the record.
            'crash.operation' => isset($record['operation']) && is_string($record['operation']) ? $record['operation'] : null,
            'crash.operation_elapsed_ms' => isset($record['operation_elapsed_ns'])
                ? round(Cast::int($record['operation_elapsed_ns']) / 1_000_000, 3)
                : null,
            'crash.fault_address' => isset($record['fault_address']) && is_string($record['fault_address']) ? $record['fault_address'] : null,
            'crash.program_counter' => isset($record['program_counter']) && is_string($record['program_counter']) ? $record['program_counter'] : null,
            // Present only when the faulting instruction was plausibly inside
            // the extension itself — which is the question worth asking first.
            'crash.module_offset' => isset($record['module_offset']) ? Cast::int($record['module_offset']) : null,
            'crash.breadcrumbs' => $this->breadcrumbs($record['breadcrumbs'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);

        $this->telemetry->recordEvent(new TelemetryEvent(
            name: 'crash.recorded',
            timeUnixNano: Cast::int($record['timestamp_ns'] ?? null) ?: (int) (microtime(true) * 1e9),
            attributes: $attributes,
            traceId: is_string($record['trace_id'] ?? null) ? $record['trace_id'] : null,
            spanId: is_string($record['span_id'] ?? null) ? $record['span_id'] : null,
            severityNumber: 21,
            severityText: 'FATAL',
        ));

        $this->telemetry
            ->counter('runtime.crashes', 'Processes that died on a fatal signal', '{crash}')
            ->inc(1, ['signal' => $signal, 'unit' => $unit]);

        return $attributes;
    }

    /**
     * The tail of the ring, as JSON — the last things the process did before
     * it died. Labels are copies made at write time, because after a segfault
     * there is no frame table left to resolve an id against.
     */
    private function breadcrumbs(mixed $raw): ?string
    {
        $crumbs = Cast::array($raw);

        if ($crumbs === []) {
            return null;
        }

        $keep = max(1, Cast::int(config('telemetry.native.crash_breadcrumbs'), 32));
        $tail = [];

        foreach (array_slice(array_values($crumbs), -$keep) as $crumb) {
            $crumb = Cast::stringKeyedArray($crumb);

            $tail[] = [
                'seq' => Cast::int($crumb['seq'] ?? null),
                'type' => Cast::string($crumb['type'] ?? null, 'unknown'),
                'label' => Cast::string($crumb['label'] ?? null),
                'ts_ns' => Cast::int($crumb['ts_ns'] ?? null),
            ];
        }

        return json_encode($tail, JSON_UNESCAPED_SLASHES) ?: null;
    }
}
