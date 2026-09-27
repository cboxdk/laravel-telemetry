<?php

declare(strict_types=1);

// In-memory SQLite: the queries have to be real, because the query
// listener is one of the costs being measured — but the database must
// not be, or the harness measures disk.
return [
    'default' => 'sqlite',
    'connections' => [
        'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
    ],

    // The metric store's Redis, and the reason it is here at all: without
    // a `redis.default` connection every metric write throws
    // `InvalidArgumentException: Redis connection [default] not
    // configured`, FailSafe swallows it exactly as designed, and the rig
    // reports eighteen thousand healthy requests with an empty Redis. The
    // fail-safe behaved; the measurement did not happen. A load test that
    // cannot tell those apart is not measuring the store.
    'redis' => [
        'client' => 'phpredis',
        'default' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => 0,
        ],
    ],
];
