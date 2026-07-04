<?php

declare(strict_types=1);

use Cbox\Telemetry\Support\GeoResolver;

it('is a silent no-op without the optional geoip2 package or a database', function () {
    expect((new GeoResolver(null))->resolve('8.8.8.8'))->toBe([])
        ->and((new GeoResolver('/nonexistent/GeoLite2-Country.mmdb'))->resolve('8.8.8.8'))->toBe([]);
});

it('returns nothing for an empty ip', function () {
    expect((new GeoResolver('/some.mmdb'))->resolve(null))->toBe([])
        ->and((new GeoResolver('/some.mmdb'))->resolve(''))->toBe([]);
});
