<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

use Cbox\Telemetry\TelemetryManager;

/**
 * Resolves `client.geo.*` from an IP using a MaxMind GeoLite2/GeoIP2 database
 * at collection time — so a raw IP can be dropped afterwards (privacy).
 *
 * The `geoip2/geoip2` package is an OPTIONAL suggestion, not a requirement:
 * without it (or without a configured database) this is a silent no-op and
 * you can still supply geo through {@see TelemetryManager::resolveClientGeoUsing()}
 * (e.g. Cloudflare's `CF-IPCountry`). The reader is built lazily on first use
 * — never in the service provider — and cached for the process (an Octane
 * win), so there is no boot-time I/O.
 */
final class GeoResolver
{
    private bool $attempted = false;

    private ?object $reader = null;

    public function __construct(private readonly ?string $database = null) {}

    /**
     * @return array<string, scalar|null>
     */
    public function resolve(?string $ip): array
    {
        $reader = $this->reader();

        if ($reader === null || $ip === null || $ip === '') {
            return [];
        }

        try {
            /** @var object{country: object{isoCode: ?string, name: ?string}, continent: object{code: ?string}} $record */
            $record = $reader->country($ip); // @phpstan-ignore-line optional dep

            return array_filter([
                'client.geo.country' => $record->country->isoCode,
                'client.geo.continent.code' => $record->continent->code,
            ], static fn ($v): bool => $v !== null && $v !== '');
        } catch (\Throwable) {
            // Private/unknown IP (AddressNotFoundException) or a bad db — geo
            // is best-effort, never fatal.
            return [];
        }
    }

    private function reader(): ?object
    {
        if ($this->attempted) {
            return $this->reader;
        }

        $this->attempted = true;

        $readerClass = 'GeoIp2\\Database\\Reader';

        if ($this->database === null || ! class_exists($readerClass) || ! is_file($this->database)) {
            return null;
        }

        try {
            /** @var object $reader */
            $reader = new $readerClass($this->database);
            $this->reader = $reader;
        } catch (\Throwable) {
            $this->reader = null;
        }

        return $this->reader;
    }
}
