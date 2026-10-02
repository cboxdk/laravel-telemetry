<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Illuminate\Contracts\Redis\Connector;
use Illuminate\Redis\RedisManager;

/**
 * Hands out connectors that time their handshake.
 *
 * `connector()` is not told which connection it is building, so the name is
 * captured on the way through `resolve()` and read back here. Both methods
 * defer to the parent for the work itself.
 *
 * Deliberately NOT `final` — it replaces the 'redis' binding, so a final
 * class breaks the standard `Redis::shouldReceive(...)` / `partialMock()`
 * test pattern: Mockery cannot mock a final class, and the facade mocks the
 * bound instance's class. Laravel's own RedisManager is non-final for the
 * same reason. Treat it as internal; do not extend it yourself.
 *
 * @internal
 */
class InstrumentedRedisManager extends RedisManager
{
    private ?string $resolving = null;

    /** @var list<string> */
    private array $ignoreConnections = [];

    /**
     * @param  list<string>  $connections
     */
    public function ignoreConnections(array $connections): self
    {
        $this->ignoreConnections = $connections;

        return $this;
    }

    /**
     * @param  string|null  $name
     */
    public function resolve($name = null): mixed
    {
        $previous = $this->resolving;
        $this->resolving = is_string($name) && $name !== '' ? $name : 'default';

        try {
            return parent::resolve($name);
        } finally {
            $this->resolving = $previous;
        }
    }

    /**
     * Time each client where its socket is actually opened.
     *
     * phpredis opens it in the connector: `PhpRedisConnector::connect()`
     * builds the client and calls `connect()` on it, so the connector is
     * timed. predis does NOT — `PredisConnector::connect()` returns
     * `new Client(...)`, whose constructor only assembles objects, and the
     * socket opens on the first command. Timing the connector there would
     * report the handshake as a few microseconds of object construction:
     * not merely useless but misleading, because it rules out a slow
     * connect that may be exactly what is wrong. So predis is handed a
     * connection factory instead, and each connection times itself when it
     * connects ({@see TimedPredisConnector}).
     *
     * A custom creator is left alone for the same reason — we cannot know
     * whether it connects eagerly, and a wrong number is worse than none.
     */
    protected function connector(): ?Connector
    {
        $connector = parent::connector();

        if (! $connector instanceof Connector) {
            return null;
        }

        if (isset($this->customCreators[$this->driver])) {
            return $connector;
        }

        return match ($this->driver) {
            'phpredis' => new TimedRedisConnector($connector, $this->app, $this->resolving ?? 'default', $this->ignoreConnections),
            'predis' => new TimedPredisConnector($connector, $this->app, $this->resolving ?? 'default', $this->ignoreConnections),
            default => $connector,
        };
    }
}
