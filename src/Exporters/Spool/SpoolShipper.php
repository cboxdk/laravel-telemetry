<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Exporters\Spool;

use Closure;

/**
 * Drains the spool and ships merged OTLP payloads.
 *
 * Entries are popped oldest-first in chunks of $maxBatch, merged per
 * signal (OTLP top-level resourceSpans/resourceLogs are lists — payloads
 * concatenate), and posted. On a failed post the chunk is requeued at
 * the front and draining stops — the next tick retries, nothing is lost
 * to a collector hiccup.
 */
final class SpoolShipper
{
    private const PATHS = ['traces' => '/v1/traces', 'logs' => '/v1/logs'];

    private const ROOTS = ['traces' => 'resourceSpans', 'logs' => 'resourceLogs'];

    /**
     * @param  Closure(string, array<string, mixed>): bool  $post  path, payload => success
     */
    public function __construct(
        private readonly Spool $spool,
        private readonly Closure $post,
    ) {}

    /**
     * @return array{shipped: int, requeued: int}
     */
    public function ship(int $maxBatch = 200): array
    {
        $shipped = 0;

        while (($entries = $this->spool->pop($maxBatch)) !== []) {
            foreach ($this->merge($entries) as $signal => $payload) {
                if (! ($this->post)(self::PATHS[$signal], $payload)) {
                    // Collector down — put the whole chunk back, oldest
                    // first, and let the next tick retry.
                    $this->spool->requeue($entries);

                    return ['shipped' => $shipped, 'requeued' => count($entries)];
                }
            }

            $shipped += count($entries);
        }

        return ['shipped' => $shipped, 'requeued' => 0];
    }

    /**
     * @param  list<array{signal: string, payload: array<string, mixed>}>  $entries
     * @return array<string, array<string, mixed>>
     */
    private function merge(array $entries): array
    {
        $merged = [];

        foreach ($entries as $entry) {
            $signal = $entry['signal'];
            $root = self::ROOTS[$signal] ?? null;

            if ($root === null) {
                continue;
            }

            $resources = $entry['payload'][$root] ?? [];

            if (! is_array($resources)) {
                continue;
            }

            $merged[$signal][$root] = [...($merged[$signal][$root] ?? []), ...$resources];
        }

        return $merged;
    }
}
