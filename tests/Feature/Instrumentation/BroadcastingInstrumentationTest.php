<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Instrumentation\InstrumentedBroadcastManager;
use Cbox\Telemetry\Testing\CollectingExporter;
use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Collection;

class BroadcastingInstrumentationTestEvent implements ShouldBroadcastNow
{
    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('updates'), new PrivateChannel('orders.1')];
    }

    public function broadcastAs(): string
    {
        return 'order.updated';
    }
}

class BroadcastingInstrumentationTestPresenceEvent implements ShouldBroadcastNow
{
    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('room.1')];
    }

    public function broadcastAs(): string
    {
        return 'room.joined';
    }
}

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);
});

function broadcastFamilies(): Collection
{
    return collect(Telemetry::collect())->keyBy(fn ($f) => $f->name());
}

function broadcastSpans(CollectingExporter $collector): array
{
    return collect($collector->batches())
        ->flatMap(fn ($batch) => $batch->spans)
        ->filter(fn ($span) => str_starts_with($span->name, 'broadcast '))
        ->values()
        ->all();
}

it('spans a broadcast inside a sampled trace, tallying the root span regardless', function () {
    Telemetry::span('root', function () {
        broadcast(new BroadcastingInstrumentationTestEvent);
    });
    Telemetry::flush();

    $spans = broadcastSpans($this->collector);

    expect($spans)->toHaveCount(1)
        ->and($spans[0]->name)->toBe('broadcast order.updated')
        ->and($spans[0]->attributes()['broadcasting.driver'])->toBe('null')
        ->and($spans[0]->attributes()['broadcasting.event'])->toBe('order.updated')
        ->and($spans[0]->attributes()['broadcasting.channel.count'])->toBe(2)
        ->and($spans[0]->attributes()['broadcasting.channels'])->toBe('private+public')
        ->and($spans[0]->isDetail())->toBeTrue();

    $root = collect($this->collector->batches())->flatMap(fn ($batch) => $batch->spans)
        ->first(fn ($span) => $span->parentSpanId === null);

    expect($root->attributes()['broadcast.count'])->toBe(1);
});

it('classifies a presence channel', function () {
    Telemetry::span('root', function () {
        broadcast(new BroadcastingInstrumentationTestPresenceEvent);
    });
    Telemetry::flush();

    $spans = broadcastSpans($this->collector);

    expect($spans[0]->attributes()['broadcasting.channels'])->toBe('presence');
});

it('creates no detail span outside a sampled trace, but keeps the tally', function () {
    broadcast(new BroadcastingInstrumentationTestEvent);
    Telemetry::flush();

    expect(broadcastSpans($this->collector))->toBeEmpty();
});

/**
 * The container binds the CONCRETE BroadcastManager, and app code hints it —
 * Laravel's own docs show `public function __construct(BroadcastManager $b)`.
 * A decorator that only implemented the Factory contract satisfied the
 * interface but failed instanceof, so installing telemetry turned any such
 * constructor into a TypeError: an observability package breaking the app it
 * observes.
 */
it('still satisfies a concrete BroadcastManager type hint', function () {
    $manager = app(BroadcastManager::class);

    expect($manager)->toBeInstanceOf(InstrumentedBroadcastManager::class)
        ->and($manager)->toBeInstanceOf(BroadcastManager::class);

    $takesConcrete = fn (BroadcastManager $broadcast): string => $broadcast::class;

    expect($takesConcrete($manager))->toBe(InstrumentedBroadcastManager::class);
});

it('delegates driver registration to the real manager rather than its own empty state', function () {
    // Extending on the decorator's inherited state would register a driver
    // that the resolution path never consults.
    config()->set('broadcasting.connections.probe', ['driver' => 'probe']);

    $manager = app(BroadcastManager::class);

    $manager->extend('probe', fn () => new NullBroadcaster);

    expect($manager->connection('probe'))->toBeInstanceOf(Broadcaster::class);
});
