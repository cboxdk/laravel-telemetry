<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Exporters\Spool;

/**
 * A local buffer between the app and the OTLP endpoint. Requests push
 * serialized payloads in microseconds; the `telemetry:flush --daemon`
 * ships them in large batches on size/age thresholds — an
 * agent-plus-daemon model, with Redis standing in for a local socket.
 */
interface Spool
{
    /**
     * @param  array{signal: string, payload: array<string, mixed>}  $entry
     */
    public function push(array $entry): void;

    /**
     * Append several entries in one round trip.
     *
     * A request with both spans and events used to pay two pushes, and
     * each push is more than one command. This is the path the
     * application itself is on, so the round trips are on the
     * response's clock.
     *
     * @param  list<array{signal: string, payload: array<string, mixed>}>  $entries
     */
    public function pushMany(array $entries): void;

    /**
     * Remove and return up to $count entries, oldest first.
     *
     * @return list<array{signal: string, payload: array<string, mixed>}>
     */
    public function pop(int $count): array;

    /**
     * Put entries back at the FRONT after a failed ship, preserving order.
     *
     * @param  list<array{signal: string, payload: array<string, mixed>}>  $entries
     */
    public function requeue(array $entries): void;

    public function size(): int;
}
