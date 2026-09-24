<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Redis\Connector;
use Illuminate\Redis\Connections\Connection;

/**
 * Wraps a Redis connector so the handshake is timed.
 *
 * The package's own connections — the spool and the metric store — are
 * skipped: recording a span about opening the spool would be written back
 * into the spool.
 */
final class TimedRedisConnector implements Connector
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
        if (in_array($this->name, $this->ignoreConnections, true)) {
            return $this->connector->connect($config, $options);
        }

        return (new ConnectionTiming($this->app))->measure(
            'redis.connect',
            'redis',
            $this->name,
            $this->address($config),
            fn (): Connection => $this->connector->connect($config, $options),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $clusterOptions
     * @param  array<string, mixed>  $options
     */
    public function connectToCluster(array $config, array $clusterOptions, array $options): Connection
    {
        if (in_array($this->name, $this->ignoreConnections, true)) {
            return $this->connector->connectToCluster($config, $clusterOptions, $options);
        }

        return (new ConnectionTiming($this->app))->measure(
            'redis.connect',
            'redis',
            $this->name,
            ['db.redis.cluster' => 'true'],
            fn (): Connection => $this->connector->connectToCluster($config, $clusterOptions, $options),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string|int>
     */
    private function address(array $config): array
    {
        return array_filter([
            'server.address' => is_string($config['host'] ?? null) ? $config['host'] : null,
            'server.port' => isset($config['port']) && is_numeric($config['port']) ? (int) $config['port'] : null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
