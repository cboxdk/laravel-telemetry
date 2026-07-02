<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Metrics;

enum MetricType: string
{
    case Counter = 'counter';
    case Gauge = 'gauge';
    case Histogram = 'histogram';
}
