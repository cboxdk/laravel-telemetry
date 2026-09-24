<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;

/**
 * Times the PDO handshake behind every database connection.
 *
 * Laravel resolves PDO lazily: `make()` hands back a Connection holding a
 * closure, and the socket is not opened until the first query asks for it.
 * Timing `make()` would therefore measure nothing. This wraps the resolver
 * closure instead, which is the exact moment the connect happens — and it
 * defers to the parent for building it, so read/write splits and the
 * multi-host failover loop keep working without being reimplemented here.
 */
final class InstrumentedConnectionFactory extends ConnectionFactory
{
    public function __construct(private readonly Container $app)
    {
        parent::__construct($app);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createPdoResolver(array $config): Closure
    {
        $resolver = parent::createPdoResolver($config);
        $timing = new ConnectionTiming($this->app);

        $driver = is_string($config['driver'] ?? null) ? $config['driver'] : 'unknown';
        $name = is_string($config['name'] ?? null) ? $config['name'] : $driver;

        $attributes = array_filter([
            'server.address' => is_string($config['host'] ?? null) ? $config['host'] : null,
            'server.port' => isset($config['port']) && is_numeric($config['port']) ? (int) $config['port'] : null,
            'db.namespace' => is_string($config['database'] ?? null) ? $config['database'] : null,
        ], static fn (mixed $value): bool => $value !== null);

        return static fn (): mixed => $timing->measure('db.connect', $driver, $name, $attributes, $resolver);
    }
}
