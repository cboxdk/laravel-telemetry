<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Testing\CollectingExporter;
use Illuminate\Support\Collection;
use Laravel\Reverb\Application;
use Laravel\Reverb\Contracts\Connection;
use Laravel\Reverb\Events\ChannelCreated;
use Laravel\Reverb\Events\ChannelRemoved;
use Laravel\Reverb\Events\ConnectionPruned;
use Laravel\Reverb\Events\MessageReceived;
use Laravel\Reverb\Events\MessageSent;
use Laravel\Reverb\Protocols\Pusher\Channels\Channel;
use Laravel\Reverb\Protocols\Pusher\Channels\ChannelConnection;
use Laravel\Reverb\Protocols\Pusher\Channels\PresenceCacheChannel;
use Laravel\Reverb\Protocols\Pusher\Channels\PresenceChannel;
use Laravel\Reverb\Protocols\Pusher\Channels\PrivateChannel;
use Laravel\Reverb\Protocols\Pusher\MetricsHandler;

use function React\Promise\resolve;

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);
});

function reverbFamilies(): Collection
{
    return collect(Telemetry::collect())->keyBy(fn ($f) => $f->name());
}

function fakeReverbApp(string $id = 'app-1'): Application
{
    $app = Mockery::mock(Application::class)->makePartial();
    $app->shouldReceive('id')->andReturn($id);

    return $app;
}

function connectionFor(Application $application): Connection
{
    // Mockery chokes generating a mock for Connection (its abstract
    // control() re-declares Frame::OP_PING — an int — as a string-typed
    // default; fine for a real subclass, not for Mockery's codegen).
    // A tiny concrete stand-in sidesteps that entirely.
    return new class($application) extends Connection
    {
        public function __construct(private readonly Application $fakeApp)
        {
            //
        }

        public function identifier(): string
        {
            return 'test-connection';
        }

        public function id(): string
        {
            return 'test-connection';
        }

        public function send(string $message): void
        {
            //
        }

        public function control(string $type = '9'): void
        {
            //
        }

        public function terminate(): void
        {
            //
        }

        public function app(): Application
        {
            return $this->fakeApp;
        }
    };
}

function fakeReverbConnection(string $appId = 'app-1'): Connection
{
    return connectionFor(fakeReverbApp($appId));
}

it('counts messages by direction', function () {
    $connection = fakeReverbConnection();

    app('events')->dispatch(new MessageSent($connection, '{}'));
    app('events')->dispatch(new MessageReceived($connection, '{}'));

    $samples = reverbFamilies()['reverb.messages']->samples;
    $byDirection = collect($samples)->keyBy(fn ($s) => $s->labels['direction']);

    expect($byDirection['sent']->labels['app'])->toBe('app-1')
        ->and($byDirection['sent']->value)->toBe(1.0)
        ->and($byDirection['received']->value)->toBe(1.0);
});

it('classifies channel lifecycle events by bounded type, never the raw name', function () {
    $public = Mockery::mock(Channel::class)->makePartial();
    $private = Mockery::mock(PrivateChannel::class)->makePartial();
    $presence = Mockery::mock(PresenceChannel::class)->makePartial();
    $presenceCache = Mockery::mock(PresenceCacheChannel::class)->makePartial();

    app('events')->dispatch(new ChannelCreated($public));
    app('events')->dispatch(new ChannelCreated($private));
    app('events')->dispatch(new ChannelRemoved($presence));
    app('events')->dispatch(new ChannelRemoved($presenceCache));

    $samples = reverbFamilies()['reverb.channels']->samples;
    $byKey = collect($samples)->keyBy(fn ($s) => $s->labels['event'].':'.$s->labels['type']);

    expect($byKey['created:public']->value)->toBe(1.0)
        ->and($byKey['created:private']->value)->toBe(1.0)
        ->and($byKey['removed:presence']->value)->toBe(2.0);

    collect($samples)->each(fn ($s) => expect($s->labels)->toHaveKeys(['event', 'type'])->toHaveCount(2));
});

it('counts pruned connections by app', function () {
    $channelConnection = new ChannelConnection(fakeReverbConnection('app-2'));

    app('events')->dispatch(new ConnectionPruned($channelConnection));

    $sample = reverbFamilies()['reverb.connections.pruned']->samples[0];

    expect($sample->labels['app'])->toBe('app-2')
        ->and($sample->value)->toBe(1.0);
});

/**
 * Real MetricsHandler needs a live reverb:start process (ChannelManager/
 * PubSubProvider are bound at server boot, not by the service provider) —
 * a Mockery double standing in for the Pusher-compatible REST API is the
 * only practical way to test the sampling/aggregation logic in isolation.
 */
function fakeMetricsHandler(Application $application, array $connections, array $channels): MetricsHandler
{
    $handler = Mockery::mock(MetricsHandler::class);
    $handler->shouldReceive('gather')->with($application, 'connections')->andReturn(resolve($connections));
    $handler->shouldReceive('gather')->with($application, 'channels', ['info' => 'subscription_count,user_count'])->andReturn(resolve($channels));

    app()->instance(MetricsHandler::class, $handler);

    return $handler;
}

it('samples active connections and per-type channel subscribers on message traffic', function () {
    $application = fakeReverbApp('app-1');
    fakeMetricsHandler($application, ['c1', 'c2', 'c3'], [
        'private-room.1' => ['subscription_count' => 2],
        'presence-room.2' => ['user_count' => 5],
    ]);

    app('events')->dispatch(new MessageSent(connectionFor($application), '{}'));

    $families = reverbFamilies();

    expect($families['reverb.connections.active']->samples[0]->value)->toBe(3.0)
        ->and($families['reverb.connections.active']->samples[0]->labels['app'])->toBe('app-1');

    $byType = collect($families['reverb.channels.subscribers']->samples)->keyBy(fn ($s) => $s->labels['type']);

    expect($byType['private']->value)->toBe(2.0)
        ->and($byType['presence']->value)->toBe(5.0)
        ->and($byType['public']->value)->toBe(0.0);
});

it('throttles occupancy sampling per app instead of sampling on every message', function () {
    $application = fakeReverbApp('app-1');
    $handler = fakeMetricsHandler($application, ['c1'], []);
    $connection = connectionFor($application);

    app('events')->dispatch(new MessageSent($connection, '{}'));
    app('events')->dispatch(new MessageSent($connection, '{}'));
    app('events')->dispatch(new MessageSent($connection, '{}'));

    $handler->shouldHaveReceived('gather')->with($application, 'connections')->once();
});
