<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Exporters\Spool;

use Cbox\Telemetry\Support\ExportResult;
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

    /**
     * @param  Closure(string, array<string, mixed>): ExportResult  $post  path, payload => result
     */
    public function __construct(
        private readonly Spool $spool,
        private readonly Closure $post,
    ) {}

    /**
     * @return array{shipped: int, requeued: int, dropped: int}
     */
    public function ship(int $maxBatch = 200): array
    {
        $shipped = 0;
        $requeued = 0;
        $dropped = 0;

        while (($entries = $this->spool->pop($maxBatch)) !== []) {
            $stop = false;

            foreach ($this->groupBySignal($entries) as $signal => $signalEntries) {
                $result = ($this->post)(self::PATHS[$signal], $this->merge($signal, $signalEntries));

                if ($result->success) {
                    $shipped += count($signalEntries);

                    continue;
                }

                if ($result->retryable) {
                    // Collector down — only THIS signal's entries go back,
                    // oldest first; delivered signals stay delivered. Stop
                    // draining this tick, retry next.
                    $this->spool->requeue($signalEntries);
                    $requeued += count($signalEntries);
                    $stop = true;

                    continue;
                }

                // Permanent rejection — drop, don't loop forever.
                $dropped += count($signalEntries);
            }

            if ($stop) {
                break;
            }
        }

        return ['shipped' => $shipped, 'requeued' => $requeued, 'dropped' => $dropped];
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
