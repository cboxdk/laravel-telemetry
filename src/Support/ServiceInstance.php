<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * The default `service.instance.id`: whatever holds the counters.
 *
 * Prometheus's and Mimir's OTLP receivers derive the `instance` label
 * from `service.instance.id` and nothing else, so it has to name the one
 * thing that owns a cumulative total. Name too little and two hosts'
 * totals land in one series, which jumps between them and reads as a
 * counter reset at every jump. Name too much and one total is split
 * across several series, which `sum()` then counts several times.
 *
 * - A store on this host (APCu, SQLite, the array store, Redis on
 *   loopback or a socket) owns this host's totals: the id is `host.name`,
 *   the same in every PHP-FPM worker. A per-process id would be as
 *   unique, and would multiply every series by the worker count.
 * - A shared Redis owns the fleet's total, exported by whichever host
 *   `onOneServer` picked: the id fingerprints the store, so every host
 *   derives the same one. Credentials are never part of it.
 */
final class ServiceInstance
{
    public static function id(Repository $config, string $hostName): string
    {
        $driver = Cast::string($config->get('telemetry.store'), 'redis');

        if ($driver !== 'redis') {
            return $hostName;
        }

        $connection = Cast::string($config->get('telemetry.stores.redis.connection'), 'default');
        $settings = $config->get('database.redis.'.$connection);
        $settings = is_array($settings) ? $settings : $config->get('database.redis.clusters.'.$connection);
        $settings = is_array($settings) ? $settings : [];

        if (self::isLoopback($settings)) {
            return $hostName;
        }

        $identity = [
            'store' => self::endpoints($settings, $connection),
            'redis_prefix' => $config->get('database.redis.options.prefix'),
            'prefix' => $config->get('telemetry.stores.redis.prefix'),
        ];

        return 'redis-'.substr(hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)), 0, 12);
    }

    /**
     * @param  array<mixed>  $settings
     */
    private static function isLoopback(array $settings): bool
    {
        $url = $settings['url'] ?? null;

        if (($settings['scheme'] ?? null) === 'unix' || (is_string($url) && str_starts_with($url, 'unix:'))) {
            return true;
        }

        $host = is_string($url) && $url !== '' ? parse_url($url, PHP_URL_HOST) : ($settings['host'] ?? null);

        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = trim(strtolower($host), '[]');

        return str_starts_with($host, '/')
            || $host === 'localhost'
            || $host === '::1'
            || str_starts_with($host, '127.');
    }

    /**
     * Where the store lives — host, port, database — and nothing that
     * authenticates to it. A cluster is its list of nodes.
     *
     * @param  array<mixed>  $settings
     * @return list<string>|string
     */
    private static function endpoints(array $settings, string $connection): array|string
    {
        if (array_is_list($settings) && $settings !== []) {
            $nodes = array_map(
                static fn (mixed $node): string => is_array($node) ? self::endpoint($node) : '',
                $settings,
            );
            sort($nodes);

            return $nodes;
        }

        $endpoint = self::endpoint($settings);

        // Nothing to go on: the connection's name is still the same on
        // every host of the app, which is what the id must be.
        return $endpoint === ':/' ? 'connection:'.$connection : $endpoint;
    }

    /**
     * @param  array<mixed>  $node
     */
    private static function endpoint(array $node): string
    {
        $url = $node['url'] ?? null;
        $parts = is_string($url) && $url !== '' ? parse_url($url) : false;

        $host = is_array($parts) ? ($parts['host'] ?? '') : Cast::string($node['host'] ?? null, '');
        $port = is_array($parts) ? ($parts['port'] ?? '') : Cast::string($node['port'] ?? null, '');
        $database = is_array($parts) ? ltrim($parts['path'] ?? '', '/') : Cast::string($node['database'] ?? null, '');

        return strtolower($host).':'.$port.'/'.$database;
    }
}
