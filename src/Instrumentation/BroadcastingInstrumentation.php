<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Contracts\Container\Container;

/**
 * Driver-agnostic broadcasting instrumentation: `broadcast.count` root-span
 * tally + a `broadcast {event}` detail span per `Broadcaster::broadcast()`
 * call, whatever the underlying driver (Pusher, Ably, Reverb, Redis, Log, …).
 *
 * Reverb gets its OWN richer instrumentation (connection/channel occupancy,
 * message direction) from its server-side events — this is the generic
 * layer every driver shares: the outgoing broadcast itself.
 *
 * Implemented via Container::extend() rather than an event listener —
 * Laravel fires no "broadcasting" event, so the Factory contract is the
 * only stable extension point. Extends the CONCRETE BroadcastManager
 * class, not the Factory interface: BroadcastServiceProvider is a
 * DeferrableProvider, so `Factory::class`'s alias to it doesn't exist
 * until first resolved, and extend() resolves aliases at REGISTRATION
 * time — extending the alias before it exists would silently attach
 * to the wrong key and never fire. The concrete key needs no alias, so
 * this works regardless of load order, and never forces the deferred
 * provider to load early (forcing it — e.g. via a throwaway make()
 * call — was tried and had to be reverted: it triggers extend()'s
 * eager already-resolved-instance path, whose rebound() call had an
 * unrelated side effect on tail-mode span retention in other tests).
 * A safe no-op if broadcasting was never installed at all — extend()
 * on a truly unbound abstract just registers for whenever it's built.
 */
final class BroadcastingInstrumentation
{
    public function register(Container $container): void
    {
        FailSafe::guard(function () use ($container): void {
            $container->extend(BroadcastManager::class, function (Factory $manager, Container $app) {
                if ($manager instanceof InstrumentedBroadcastManager) {
                    return $manager;
                }

                return new InstrumentedBroadcastManager($manager, $app->make(TelemetryManager::class));
            });
        });
    }
}
