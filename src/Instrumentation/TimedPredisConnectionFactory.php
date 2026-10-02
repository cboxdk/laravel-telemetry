<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Predis\Connection\Factory;
use Predis\Connection\StreamConnection;

/**
 * predis's own connection factory, building timed stream connections.
 *
 * Every node connection predis opens goes through the client's factory —
 * a single server, each replica, and under Sentinel both the sentinels it
 * asks and the master they name — so this is the one seam that sees them
 * all.
 *
 * Only schemes still mapped to predis's own StreamConnection are swapped. A
 * scheme defined to anything else (Relay, a host's own class or callable)
 * is left as it is: we cannot know when it connects, and a wrong number is
 * worse than none.
 *
 * @internal
 */
final class TimedPredisConnectionFactory extends Factory
{
    public function __construct(
        private readonly ConnectionTiming $timing,
        private readonly string $connectionName,
    ) {
        foreach ($this->schemes as $scheme => $initializer) {
            if ($initializer === StreamConnection::class) {
                $this->schemes[$scheme] = TimedPredisStreamConnection::class;
            }
        }
    }

    public function create($parameters)
    {
        $connection = parent::create($parameters);

        if ($connection instanceof TimedPredisStreamConnection) {
            $connection->timeWith($this->timing, $this->connectionName);
        }

        return $connection;
    }
}
