<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\Cast;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Redis\Connector;
use Illuminate\Redis\Connections\Connection;
use Predis\Configuration\OptionsInterface;
use Predis\Connection\FactoryInterface;

/**
 * Wraps the predis connector so the client builds timed connections.
 *
 * Unlike {@see TimedRedisConnector} this times nothing itself: predis
 * connects on the first command, long after `connect()` has returned. It
 * hands the client a `connections` factory instead, and the connection
 * times its own handshake when it actually opens the socket.
 *
 * The factory is given as a callable, not an instance. predis uses a
 * factory instance exactly as given, so a ready-made one would lose the
 * `parameters` option (the password and database applied to every node,
 * which is how Sentinel setups authenticate to the master). The callable is
 * handed the client's options and configures the factory the way predis's
 * own default would.
 *
 * Left alone: the package's own connections, and any connection that
 * already sets `connections` — that is the host's factory, not ours.
 *
 * @internal
 */
final class TimedPredisConnector implements Connector
{
    /**
     * @param  list<string>  $ignoreConnections
     */
    public function __construct(
        private readonly Connector $connector,
        private readonly Container $app,
        private readonly string $name,
        private readonly array $ignoreConnections,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $options
     */
    public function connect(array $config, array $options): Connection
    {
        return $this->connector->connect($config, $this->withTimedConnections($options, $config));
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $clusterOptions
     * @param  array<string, mixed>  $options
     */
    public function connectToCluster(array $config, array $clusterOptions, array $options): Connection
    {
        return $this->connector->connectToCluster($config, $clusterOptions, $this->withTimedConnections($options, $config, $clusterOptions));
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  ...$overrides  option sets the connector merges over $options
     * @return array<string, mixed>
     */
    private function withTimedConnections(array $options, array ...$overrides): array
    {
        if (in_array($this->name, $this->ignoreConnections, true) || array_key_exists('connections', $options)) {
            return $options;
        }

        foreach ($overrides as $override) {
            $nested = Cast::array($override['options'] ?? []);

            if (array_key_exists('connections', $override) || array_key_exists('connections', $nested)) {
                return $options;
            }
        }

        $options['connections'] = $this->factory(new ConnectionTiming($this->app), $this->name);

        return $options;
    }

    /**
     * @return Closure(OptionsInterface): FactoryInterface
     */
    private function factory(ConnectionTiming $timing, string $name): Closure
    {
        return static function (OptionsInterface $options) use ($timing, $name): FactoryInterface {
            $factory = new TimedPredisConnectionFactory($timing, $name);

            if ($options->defined('parameters')) {
                $factory->setDefaultParameters(Cast::array($options->parameters));
            }

            // predis 3 only; on predis 2 the option does not exist, so this
            // is never true there.
            if ($options->defined('upstream_driver')) {
                $factory->setUpstreamDriver(Cast::string($options->upstream_driver));
            }

            return $factory;
        };
    }
}
