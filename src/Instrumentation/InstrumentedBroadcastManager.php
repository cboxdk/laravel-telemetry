<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\Cast;
use Cbox\Telemetry\TelemetryManager;
use Closure;
use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PendingBroadcast;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;

/**
 * Wraps whatever `connection()` resolves in an InstrumentedBroadcaster,
 * driver-agnostic.
 *
 * Extends the concrete BroadcastManager rather than only implementing
 * Factory, because that is the class the container binds and the type app
 * code hints. A decorator that merely implemented the contract satisfied
 * `Factory` but failed `instanceof BroadcastManager`, so any controller,
 * service or resolving callback type-hinting Laravel's class got a
 * TypeError the moment telemetry was installed — an observability package
 * breaking the app it observes. Same fix the filesystem got in 1.1.0.
 *
 * Behaviour is DELEGATED to the wrapped manager, never inherited: the real
 * manager owns the resolved drivers and anything registered through
 * `extend()`, while this instance's inherited `$drivers`/`$customCreators`
 * stay empty. Every public method of the parent is therefore overridden —
 * `__call` cannot cover them, because an inherited method exists and would
 * silently run against that empty state.
 */
final class InstrumentedBroadcastManager extends BroadcastManager
{
    public function __construct(
        private readonly Factory $manager,
        private readonly TelemetryManager $telemetry,
        Container $app,
    ) {
        // The parent stores this but nothing inherited is ever reached — every
        // public method delegates to the wrapped manager, which owns the real
        // state. It is passed only so the parent constructor is satisfied.
        parent::__construct($app);
    }

    public function connection($name = null): Broadcaster
    {
        $broadcaster = $this->manager->connection($this->connectionName($name));

        if ($broadcaster instanceof InstrumentedBroadcaster) {
            return $broadcaster;
        }

        return new InstrumentedBroadcaster($broadcaster, $this->telemetry, $this->connectionName($name));
    }

    public function driver($name = null): Broadcaster
    {
        return $this->connection($name);
    }

    /**
     * @param  array<string, mixed>|null  $attributes
     */
    public function routes(?array $attributes = null): void
    {
        $this->forward(__FUNCTION__, [$attributes]);
    }

    /**
     * @param  array<string, mixed>|null  $attributes
     */
    public function userRoutes(?array $attributes = null): void
    {
        $this->forward(__FUNCTION__, [$attributes]);
    }

    /**
     * @param  array<string, mixed>|null  $attributes
     */
    public function channelRoutes(?array $attributes = null): void
    {
        $this->forward(__FUNCTION__, [$attributes]);
    }

    public function socket($request = null): ?string
    {
        $socket = $this->forward(__FUNCTION__, [$request]);

        return is_string($socket) ? $socket : null;
    }

    /**
     * @param  Channel|string|array<int, Channel|string>  $channels
     */
    public function on(Channel|string|array $channels): AnonymousEvent
    {
        /** @var AnonymousEvent */
        return $this->forward(__FUNCTION__, [$channels]);
    }

    public function private(string $channel): AnonymousEvent
    {
        /** @var AnonymousEvent */
        return $this->forward(__FUNCTION__, [$channel]);
    }

    public function presence(string $channel): AnonymousEvent
    {
        /** @var AnonymousEvent */
        return $this->forward(__FUNCTION__, [$channel]);
    }

    public function event($event = null): PendingBroadcast
    {
        /** @var PendingBroadcast */
        return $this->forward(__FUNCTION__, [$event]);
    }

    public function queue($event): void
    {
        $this->forward(__FUNCTION__, [$event]);
    }

    public function getDefaultDriver(): string
    {
        return Cast::string($this->forward(__FUNCTION__, []), 'null');
    }

    public function setDefaultDriver($name): void
    {
        $this->forward(__FUNCTION__, [$name]);
    }

    public function purge($name = null): void
    {
        $this->forward(__FUNCTION__, [$name]);
    }

    public function extend($driver, Closure $callback): static
    {
        // Must reach the real manager: a custom driver registered on this
        // instance would be invisible to the resolution that actually happens.
        $this->forward(__FUNCTION__, [$driver, $callback]);

        // Fluent methods return the decorator, not the thing it wraps —
        // handing back the raw manager would let the caller chain straight
        // past the instrumentation.
        return $this;
    }

    public function getApplication(): Application
    {
        /** @var Application */
        return $this->forward(__FUNCTION__, []);
    }

    public function setApplication($app): static
    {
        $this->forward(__FUNCTION__, [$app]);

        return $this;
    }

    public function forgetDrivers(): static
    {
        $this->forward(__FUNCTION__, []);

        return $this;
    }

    /**
     * @param  array<int, mixed>  $parameters
     */
    public function __call($method, $parameters): mixed
    {
        return $this->forward($method, $parameters);
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function forward(string $method, array $arguments): mixed
    {
        /** @var callable $callable */
        $callable = [$this->manager, $method];

        return $callable(...$arguments);
    }

    private function connectionName(mixed $name): string
    {
        if (is_string($name) && $name !== '') {
            return $name;
        }

        if ($name instanceof \BackedEnum) {
            return (string) $name->value;
        }

        if ($name instanceof \UnitEnum) {
            return $name->name;
        }

        $default = config('broadcasting.default');

        return is_string($default) && $default !== '' ? $default : 'null';
    }
}
