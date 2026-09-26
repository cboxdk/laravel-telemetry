<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Metrics;

/**
 * The four OpenTelemetry instrument shapes this package can export.
 *
 * `UpDownCounter` is the one people skip, and skipping it is how a
 * perfectly correct number becomes a wrong chart. Bytes of memory in use
 * are a SUM that happens to go down sometimes: adding them across ten
 * hosts is the fleet's memory, which is the question you actually have.
 * Recorded as a gauge, a backend is entitled to average them instead, and
 * the answer is a tenth of the truth. Conversely a monotonic counter may
 * be `rate()`'d and a gauge may not — the shape is what tells the backend
 * which arithmetic is allowed.
 */
enum MetricType: string
{
    case Counter = 'counter';
    case UpDownCounter = 'updowncounter';
    case Gauge = 'gauge';
    case Histogram = 'histogram';
}
