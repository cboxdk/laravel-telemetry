<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Exceptions;

final class InvalidMetricName extends TelemetryException
{
    public function __construct(string $name)
    {
        parent::__construct(
            "Invalid metric name [{$name}]. Names must be lowercase, start with a letter, ".
            'and may contain letters, digits, dots and underscores — e.g. "http.server.request.duration".'
        );
    }
}
