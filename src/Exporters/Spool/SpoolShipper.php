<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Exporters\Spool;

use Cbox\Telemetry\Support\ExportOutcome;
use Cbox\Telemetry\Support\ExportResult;
use Cbox\Telemetry\Support\SharedState;
use Closure;

/**
 * Drains the spool and ships merged OTLP payloads.
 *
 * Entries are popped oldest-first in chunks of $maxBatch and grouped BY
 * SIGNAL, then each signal's payloads are merged (OTLP top-level
 * resourceSpans/resourceLogs are lists — they concatenate) and posted
 * separately. Per-signal handling matters: if traces deliver but logs
 * fail, only the log entries requeue — the delivered traces are never
 * re-shipped, so a partial failure can't duplicate spans.
 *
 * Failure classification decides the entries' fate:
 * - retryable (429/503, network): requeued at the front, retried next
 *   tick — nothing lost to a collector hiccup, and draining stops so we
 *   don't hammer an unhealthy endpoint.
 * - permanent (4xx, serialization): dropped. A poison record (one
 *   malformed/oversized batch the collector will always reject) must not
 *   wedge the spool forever behind a head-of-line block.
 */
final class SpoolShipper
{
    private const PATHS = ['traces' => '/v1/traces', 'logs' => '/v1/logs'];

    private const ROOTS = ['traces' => 'resourceSpans', 'logs' => 'resourceLogs'];

    /** Where a Retry-After the backend asked for is remembered. */
    private const COOLDOWN_KEY = 'spool:cooldown';

    /**
     * @param  Closure(string, array<string, mixed>): ExportResult  $post  path, payload => result
     */
    public function __construct(
        private readonly Spool $spool,
        private readonly Closure $post,
    ) {}

    /**
     * Drain the spool, within a budget.
     *
     * The budget is not a tuning knob, it is what keeps the daemon a
     * daemon. Draining until empty meant one call could run for as long
     * as the backlog took — and a backlog is exactly what a collector
     * outage leaves behind. For the whole of that call the daemon does
     * not flush metrics and does not look at its stop flag, so metrics
     * stop arriving and SIGTERM is answered by the supervisor's kill
     * timeout instead of by the process.
     *
     * A drain that stops on the budget reports drained: false, and the
     * caller comes straight back rather than sleeping.
     *
     * @param  int  $maxEntries  entries to handle before returning
     * @param  (Closure(): bool)|null  $shouldStop  asked between batches
     * @param  float  $maxSeconds  wall clock to spend before returning; 0 is unlimited
     */
    public function ship(int $maxBatch = 200, int $maxEntries = 10_000, ?Closure $shouldStop = null, float $maxSeconds = 0.0): ShipResult
    {
        $deadline = $maxSeconds > 0.0 ? microtime(true) + $maxSeconds : 0.0;

        // A cooldown the backend itself asked for, still running.
        if (time() < SharedState::deadline(self::COOLDOWN_KEY)) {
            return new ShipResult(drained: false, waiting: true);
        }

        $shipped = 0;
        $requeued = 0;
        $dropped = 0;
        $handled = 0;
        $drained = true;

        /** @var list<ExportOutcome> $failures */
        $failures = [];

        while (true) {
            // Before popping, so a drain that is already out of budget
            // takes nothing off the list — entries in flight when a
            // supervisor loses patience are the ones at risk.
            if ($this->spent($deadline, $handled, $maxEntries, $shouldStop)) {
                $drained = false;

                break;
            }

            $entries = $this->spool->pop($maxBatch);

            if ($entries === []) {
                break;
            }

            $stop = false;
            $handled += count($entries);

            foreach ($this->groupBySignal($entries) as $signal => $signalEntries) {
                // Checked before each POST, not only between batches. A
                // batch carries two signals and a POST can take the
                // whole HTTP timeout, so a budget consulted once per
                // batch is two timeouts wide — and after SIGTERM those
                // are spent past the supervisor's patience, on entries
                // already taken off the list.
                // Time and the stop flag only — not the entry count.
                // These entries are already off the list, and the
                // count budget's job is to decide whether to take
                // MORE, which the check above the pop does.
                if ($stop || $this->spent($deadline, 0, PHP_INT_MAX, $shouldStop)) {
                    $this->spool->requeue($signalEntries);
                    $requeued += count($signalEntries);
                    $drained = false;
                    $stop = true;

                    continue;
                }

                $result = ($this->post)(self::PATHS[$signal], $this->merge($signal, $signalEntries));

                if ($result->success) {
                    $shipped += count($signalEntries);

                    // Accepted, but not cleanly: the backend rejected
                    // items, or its answer could not be read. Counting
                    // that as a clean delivery is how a partial loss
                    // goes unreported by the one command that watches
                    // the spool.
                    if ($result->rejected > 0 || $result->reason !== null) {
                        $failures[] = ExportOutcome::of("otlp {$signal}", $result);
                    }

                    continue;
                }

                // Why it failed travels with the counts. A drained spool
                // that dropped everything used to look like a drained
                // spool that delivered everything.
                $failures[] = ExportOutcome::of("otlp {$signal}", $result);

                if ($result->retryable) {
                    // Collector down — only THIS signal's entries go back,
                    // oldest first; delivered signals stay delivered. Stop
                    // draining this tick, retry next.
                    $this->spool->requeue($signalEntries);
                    $requeued += count($signalEntries);
                    $stop = true;

                    // And honour what it asked for. Retry-After is the
                    // backend saying how long it needs; coming back on
                    // the next tick regardless is what turns an
                    // overloaded collector into a hammered one.
                    if (($result->retryAfterSeconds ?? 0) > 0) {
                        SharedState::remember(self::COOLDOWN_KEY, time() + (int) $result->retryAfterSeconds);
                    }

                    continue;
                }

                // Permanent rejection — drop, don't loop forever.
                $dropped += count($signalEntries);
            }

            if ($stop) {
                break;
            }

        }

        return new ShipResult($shipped, $requeued, $dropped, $failures, $drained);
    }

    /**
     * Whether this drain has spent its budget.
     *
     * Entries AND wall clock: an entry budget bounds the work but not
     * the time it takes, and ten thousand entries at two seconds a
     * POST is a hundred seconds in which the daemon flushes no metrics
     * and never looks at its stop flag.
     *
     * @param  (Closure(): bool)|null  $shouldStop
     */
    private function spent(float $deadline, int $handled, int $maxEntries, ?Closure $shouldStop): bool
    {
        return $handled >= $maxEntries
            || ($deadline > 0.0 && microtime(true) >= $deadline)
            || ($shouldStop !== null && $shouldStop());
    }

    /**
     * @param  list<array{signal: string, payload: array<string, mixed>}>  $entries
     * @return array<string, list<array{signal: string, payload: array<string, mixed>}>>
     */
    private function groupBySignal(array $entries): array
    {
        $grouped = [];

        foreach ($entries as $entry) {
            if (isset(self::PATHS[$entry['signal']])) {
                $grouped[$entry['signal']][] = $entry;
            }
        }

        return $grouped;
    }

    /**
     * @param  list<array{signal: string, payload: array<string, mixed>}>  $entries
     * @return array<string, mixed>
     */
    private function merge(string $signal, array $entries): array
    {
        $root = self::ROOTS[$signal];
        $resources = [];

        foreach ($entries as $entry) {
            $items = $entry['payload'][$root] ?? [];

            if (is_array($items)) {
                $resources = [...$resources, ...$items];
            }
        }

        return [$root => $resources];
    }
}
