<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Exceptions;

use Cbox\Telemetry\Metrics\MetricType;

final class InstrumentTypeMismatch extends TelemetryException
{
    public function __construct(string $name, MetricType $registered, MetricType $requested)
    {
        parent::__construct(
            "Metric [{$name}] is already registered as a {$registered->value} ".
            "and cannot also be used as a {$requested->value}."
        );
    }
}
