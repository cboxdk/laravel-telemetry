<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\Cast;
use Predis\Connection\StreamConnection;

/**
 * A predis stream connection that times its own handshake.
 *
 * predis opens the socket lazily, on the first command, so the connector
 * cannot time it — `PredisConnector::connect()` only builds a client. This
 * is the one place the real connect happens: DNS, TCP, TLS and the init
 * commands (AUTH, SELECT, HELLO) all run inside `connect()`.
 *
 * Untimed until {@see self::timeWith()} is called, so a connection built by
 * any other factory behaves exactly like its parent.
 *
 * @internal
 */
final class TimedPredisStreamConnection extends StreamConnection
{
    private ?ConnectionTiming $timing = null;

    private string $connectionName = 'default';

    public function timeWith(ConnectionTiming $timing, string $connectionName): void
    {
        $this->timing = $timing;
        $this->connectionName = $connectionName;
    }

    public function connect(): void
    {
        // A connected socket is a no-op for the parent too; only a connect
        // that is about to open one is worth a span. A reconnect after a
        // dropped connection gets one of its own.
        if ($this->timing === null || $this->isConnected()) {
            parent::connect();

            return;
        }

        $this->timing->measure('redis.connect', 'redis', $this->connectionName, $this->address(), function (): bool {
            parent::connect();

            return true;
        });
    }

    /**
     * The node actually dialled. Behind Sentinel that is the sentinel or the
     * master it named, not the address in the Laravel config.
     *
     * @return array<string, string|int>
     */
    private function address(): array
    {
        $parameters = $this->getParameters();

        if (Cast::string($parameters->scheme) === 'unix') {
            $path = Cast::string($parameters->path);

            return $path === '' ? [] : ['server.address' => $path];
        }

        $host = Cast::string($parameters->host);
        $port = $parameters->port;

        return array_filter([
            'server.address' => $host === '' ? null : $host,
            'server.port' => is_numeric($port) ? Cast::int($port) : null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
