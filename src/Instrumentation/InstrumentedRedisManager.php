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
 */
final class InstrumentedRedisManager extends RedisManager
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

    protected function connector(): ?Connector
    {
        $connector = parent::connector();

        if (! $connector instanceof Connector) {
            return null;
        }

        return new TimedRedisConnector(
            $connector,
            $this->app,
            $this->resolving ?? 'default',
            $this->ignoreConnections,
        );
    }
}
