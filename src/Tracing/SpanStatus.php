<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Tracing;

enum SpanStatus: int
{
    case Unset = 0;
    case Ok = 1;
    case Error = 2;
}
