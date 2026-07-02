<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

enum Signal: string
{
    case Traces = 'traces';
    case Metrics = 'metrics';
    case Events = 'events';
}
