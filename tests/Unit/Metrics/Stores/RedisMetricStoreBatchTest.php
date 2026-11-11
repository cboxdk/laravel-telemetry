<?php

declare(strict_types=1);

use Cbox\Telemetry\Metrics\MetricDefinition;
use Cbox\Telemetry\Metrics\MetricType;
use Cbox\Telemetry\Metrics\Stores\BufferedMetricStore;
use Cbox\Telemetry\Metrics\Stores\RedisMetricStore;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\Support\SharedState;
use Cbox\Telemetry\Tests\Doubles\PoolMemory;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\Connection;

/**
 * Lands every command except the second counter increment — the
 * mid-flush blip the per-command fallback must survive without
 * replaying the write that already landed.
 */
final class BlipConnection extends Connection
{
    public int $landedIncrements = 0;

    private int $increments = 0;

    public function command($method, array $parameters = [])
    {
        if ($method === 'hincrbyfloat') {
            $this->increments++;

            if ($this->increments === 2) {
                throw new RuntimeException('connection lost mid-flush');
            }

            $this->landedIncrements++;
        }

        return 1;
    }

    public function createSubscription($channels, Closure $callback, $method = 'subscribe') {}
}

it('does not replay landed writes when the per-command fallback fails partway', function () {
    SharedState::use(new PoolMemory);
    FailSafe::handleExceptionsUsing(static fn () => null);

    // A server that refuses EVAL takes the per-command fallback, where a
    // failure partway through the flush leaves the earlier writes landed.
    SharedState::remember('store:noscript:telemetry_batch:default', time() + 300);

    $connection = new BlipConnection;
    $factory = new class($connection) implements Factory
    {
        public function __construct(private readonly Connection $connection) {}

        public function connection($name = null)
        {
            return $this->connection;
        }
    };

    $store = new BufferedMetricStore(new RedisMetricStore($factory, 'default', 'telemetry_batch'));
    $definition = new MetricDefinition('orders.created', MetricType::Counter);
    $store->incrementCounter($definition, [], 1.0);
    $store->incrementCounter($definition, ['tenant' => 'acme'], 1.0);

    // The second increment throws after the first landed. The batch is
    // reported and dropped, not thrown: a kept buffer would replay the
    // first increment on the next flush and count it twice.
    $store->flushBuffer();
    $store->flushBuffer();

    expect($connection->landedIncrements)->toBe(1);
});

afterEach(function () {
    SharedState::use(null);
    FailSafe::handleExceptionsUsing(null);
});
