<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

use Cbox\Telemetry\Exceptions\SqliteUnavailable;
use PDO;

/**
 * Opens the raw PDO handles the SQLite store and spool run on.
 *
 * Deliberately not one of the app's database connections: routing
 * telemetry's own writes through the DB manager would put them in front
 * of QueryInstrumentation, and telemetry would start measuring itself.
 */
final class SqliteConnection
{
    public static function open(string $path, int $busyTimeoutMs): PDO
    {
        if (! extension_loaded('pdo_sqlite')) {
            throw new SqliteUnavailable;
        }

        if ($path !== ':memory:') {
            $directory = dirname($path);

            if (! is_dir($directory)) {
                @mkdir($directory, 0755, true);
            }
        }

        $pdo = new PDO('sqlite:'.$path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // WAL lets a reader work while a writer commits. NORMAL trades an
        // fsync per commit for one per checkpoint: a killed app keeps its
        // data, only a power cut can lose the last few writes — the right
        // trade on a phone, where full fsync is a battery bill.
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA busy_timeout = '.$busyTimeoutMs);

        return $pdo;
    }
}
