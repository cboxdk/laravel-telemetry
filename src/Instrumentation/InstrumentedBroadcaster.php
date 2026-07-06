<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\Span;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\EncryptedPrivateChannel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Throwable;

/**
 * Wraps a single connection's real Broadcaster: every broadcast() call
 * inside a detail span, always run — telemetry never blocks or
 * suppresses the actual send. Driver-agnostic: whatever
 * `broadcasting.default` (or `broadcastConnections()`) resolves to
 * (Pusher, Ably, Reverb, Redis, Log, …) gets the same coverage. Channel
 * NAMES never become labels — only the bounded public/private/presence
 * shape.
 */
final readonly class InstrumentedBroadcaster implements Broadcaster
{
    public function __construct(
        private Broadcaster $broadcaster,
        private TelemetryManager $telemetry,
        private string $connection,
    ) {}

    public function auth($request)
    {
        return $this->broadcaster->auth($request);
    }

    public function validAuthenticationResponse($request, $result)
    {
        return $this->broadcaster->validAuthenticationResponse($request, $result);
    }

    /**
     * @param  array<int, Channel|string>  $channels
     * @param  array<string, mixed>  $payload
     */
    public function broadcast(array $channels, $event, array $payload = []): void
    {
        $eventName = is_string($event) ? $event : 'unknown';

        $span = FailSafe::guard(function () use ($channels, $eventName): ?Span {
            $this->telemetry->tracer()->bumpStat('broadcast.count', 1);

            if ($this->telemetry->currentSpan()?->sampled !== true) {
                return null;
            }

            return $this->telemetry->tracer()->startSpan('broadcast '.$eventName, attributes: [
                'broadcasting.driver' => $this->connection,
                'broadcasting.event' => $eventName,
                'broadcasting.channels' => $this->channelTypeSummary($channels),
                'broadcasting.channel.count' => count($channels),
            ])->markDetail();
        });

        try {
            $this->broadcaster->broadcast($channels, $event, $payload);
        } catch (Throwable $e) {
            FailSafe::guard(function () use ($span, $e): void {
                $span?->recordException($e);
            });

            throw $e;
        } finally {
            FailSafe::guard(fn () => $span?->end());
        }
    }

    /**
     * Bounded channel shape ("private+public", "presence") — never the
     * raw channel name, which can carry an id (`private-orders.123`).
     *
     * @param  array<int, Channel|string>  $channels
     */
    private function channelTypeSummary(array $channels): string
    {
        $types = array_unique(array_map($this->channelType(...), $channels));
        sort($types);

        return implode('+', $types);
    }

    private function channelType(Channel|string $channel): string
    {
        return match (true) {
            $channel instanceof PresenceChannel => 'presence',
            $channel instanceof EncryptedPrivateChannel, $channel instanceof PrivateChannel => 'private',
            default => 'public',
        };
    }
}
