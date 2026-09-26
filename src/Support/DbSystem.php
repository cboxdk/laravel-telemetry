<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

/**
 * Laravel's driver name is not OpenTelemetry's `db.system.name`.
 *
 * Two of the five differ, and both differ silently: `pgsql` and `sqlsrv`
 * are perfectly good strings that group correctly with themselves and with
 * nothing else. A dashboard filtering `db.system.name="postgresql"` — the
 * only spelling the convention knows, and the one every other SDK in the
 * room emits — finds no PostgreSQL at all.
 */
final class DbSystem
{
    /**
     * Well-known values from the semantic-convention registry. Anything
     * not listed is passed through: an unknown driver named honestly is
     * better than one forced into a category it does not belong to.
     */
    private const NAMES = [
        'pgsql' => 'postgresql',
        'sqlsrv' => 'microsoft.sql_server',
    ];

    public static function name(string $driver): string
    {
        return self::NAMES[$driver] ?? $driver;
    }
}
