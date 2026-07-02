<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Exceptions;

final class GaugeShapeConflict extends TelemetryException
{
    public function __construct(string $name, bool $existingIsObservable)
    {
        $existing = $existingIsObservable ? 'an observable (callback) gauge' : 'a push gauge';
        $requested = $existingIsObservable ? 'a push gauge' : 'an observable (callback) gauge';

        parent::__construct(
            "Gauge [{$name}] is already registered as {$existing} and cannot also be used as {$requested}. ".
            'The two shapes would render duplicate metric families and break the Prometheus scrape.'
        );
    }
}
