<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\Cast;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Reverb\Application;
use Laravel\Reverb\Contracts\Connection;
use Laravel\Reverb\Events\ChannelCreated;
use Laravel\Reverb\Events\ChannelRemoved;
use Laravel\Reverb\Events\ConnectionPruned;
use Laravel\Reverb\Events\MessageReceived;
use Laravel\Reverb\Events\MessageSent;
use Laravel\Reverb\Protocols\Pusher\Channels\Channel;
use Laravel\Reverb\Protocols\Pusher\Channels\PresenceCacheChannel;
use Laravel\Reverb\Protocols\Pusher\Channels\PresenceChannel;
use Laravel\Reverb\Protocols\Pusher\Channels\PrivateCacheChannel;
use Laravel\Reverb\Protocols\Pusher\Channels\PrivateChannel;
use Laravel\Reverb\Protocols\Pusher\MetricsHandler;

/**
 * Reverb runs as its own long-lived process (`reverb:start`), not a normal
 * HTTP/queue request — but it still boots the full Laravel app, so this
 * package's telemetry is present the same way it is in a queue worker.
 * Reverb's own events (`Laravel\Reverb\Events\*`) are plain Dispatchable
 * events, so this is a normal listener, nothing process-specific.
 *
 * Channel names and connection ids are NEVER used as labels — they are
 * user-controlled (`private-user.42`, `presence-room.acme-corp`) and would
 * blow the cardinality budget. Only the bounded channel TYPE (public/
 * private/presence) and the operator-configured Reverb app id are used.
 *
 * Message volume can be far higher than HTTP request volume (typing
 * indicators, cursor positions) — same as `worker.memory.php`, the write
 * is a single aggregated store increment, not a per-message record, so
 * this stays cheap regardless of rate.
 *
 * Live occupancy (`reverb.connections.active`, `reverb.channels.subscribers`)
 * has no dedicated event — Reverb only exposes it via its Pusher-compatible
 * REST API (`/connections`, `/channels?info=subscription_count`), which
 * Laravel's own Pulse recorder for Reverb polls over HTTP from a separate,
 * periodic process. Since this class already runs INSIDE the reverb:start
 * process, it calls `MetricsHandler` directly — no HTTP round trip, no
 * extra scheduled process — sampled off existing message/connection
 * traffic as a free clock, throttled to once per `SAMPLE_INTERVAL_SECONDS`
 * per app. Trade-off: on an app with zero traffic the gauge goes stale
 * until the next message — acceptable for a scrape/flush-style gauge.
 */
final class ReverbInstrumentation
{
    private const SAMPLE_INTERVAL_SECONDS = 15;

    /** @var array<string, int> last occupancy-sample unix timestamp, keyed by app id */
    private array $lastSampledAt = [];

    public function __construct(private readonly Container $container) {}

    private function telemetry(): TelemetryManager
    {
        return $this->container->make(TelemetryManager::class);
    }

    public function register(Dispatcher $events): void
    {
        $events->listen(MessageSent::class, fn (MessageSent $event) => $this->messageAndSample('sent', $event->connection));
        $events->listen(MessageReceived::class, fn (MessageReceived $event) => $this->messageAndSample('received', $event->connection));
        $events->listen(ChannelCreated::class, fn (ChannelCreated $event) => $this->channelEvent('created', $event->channel));
        $events->listen(ChannelRemoved::class, fn (ChannelRemoved $event) => $this->channelEvent('removed', $event->channel));
        $events->listen(ConnectionPruned::class, $this->connectionPruned(...));
    }

    private function messageAndSample(string $direction, Connection $connection): void
    {
        $this->message($direction, Cast::string($connection->app()->id()));
        $this->sampleOccupancy($connection->app());
    }

    private function message(string $direction, string $appId): void
    {
        FailSafe::guard(function () use ($direction, $appId) {
            $this->telemetry()
                ->counter('reverb.messages', 'WebSocket messages by direction')
                ->inc(1, ['direction' => $direction, 'app' => $appId]);
        });
    }

    private function channelEvent(string $event, Channel $channel): void
    {
        FailSafe::guard(function () use ($event, $channel) {
            $this->telemetry()
                ->counter('reverb.channels', 'Channel lifecycle events by type')
                ->inc(1, ['event' => $event, 'type' => $this->channelType($channel)]);
        });
    }

    private function connectionPruned(ConnectionPruned $event): void
    {
        $application = $event->connection->connection()->app();

        FailSafe::guard(function () use ($application) {
            $this->telemetry()
                ->counter('reverb.connections.pruned', 'Stale WebSocket connections removed')
                ->inc(1, ['app' => Cast::string($application->id())]);
        });

        $this->sampleOccupancy($application);
    }

    private function sampleOccupancy(Application $application): void
    {
        $appId = Cast::string($application->id());
        $now = time();

        if (($this->lastSampledAt[$appId] ?? 0) > $now - self::SAMPLE_INTERVAL_SECONDS) {
            return;
        }

        $this->lastSampledAt[$appId] = $now;

        FailSafe::guard(function () use ($application, $appId) {
            $metrics = $this->container->make(MetricsHandler::class);

            $metrics->gather($application, 'connections')->then(
                fn (mixed $connections) => FailSafe::guard(fn () => $this->telemetry()
                    ->gauge('reverb.connections.active', description: 'Active WebSocket connections')
                    ->set((float) count(Cast::array($connections)), ['app' => $appId])),
            );

            $metrics->gather($application, 'channels', ['info' => 'subscription_count,user_count'])->then(
                fn (mixed $channels) => FailSafe::guard(fn () => $this->recordChannelOccupancy($appId, Cast::stringKeyedArray($channels))),
            );
        });
    }

    /**
     * @param  array<string, mixed>  $channels
     */
    private function recordChannelOccupancy(string $appId, array $channels): void
    {
        $byType = ['public' => 0, 'private' => 0, 'presence' => 0];

        foreach ($channels as $name => $info) {
            $info = Cast::stringKeyedArray($info);
            $type = $this->channelTypeFromName(Cast::string($name));
            $byType[$type] += Cast::int($info['subscription_count'] ?? $info['user_count'] ?? null);
        }

        $gauge = $this->telemetry()->gauge('reverb.channels.subscribers', description: 'Subscribers per channel, aggregated by bounded channel type');

        // Every known type is written every sample — including zero —
        // so a type that just emptied out doesn't leave a stale non-zero
        // gauge behind (gauges never reset themselves).
        foreach ($byType as $type => $count) {
            $gauge->set((float) $count, ['app' => $appId, 'type' => $type]);
        }
    }

    private function channelType(Channel $channel): string
    {
        return match (true) {
            $channel instanceof PresenceChannel, $channel instanceof PresenceCacheChannel => 'presence',
            $channel instanceof PrivateChannel, $channel instanceof PrivateCacheChannel => 'private',
            default => 'public',
        };
    }

    private function channelTypeFromName(string $name): string
    {
        return match (true) {
            str_starts_with($name, 'presence-') => 'presence',
            str_starts_with($name, 'private-') => 'private',
            default => 'public',
        };
    }
}
