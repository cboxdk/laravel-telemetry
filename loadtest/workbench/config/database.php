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
];
