<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

/**
 * What became of one batch handed to one exporter.
 *
 * The values double as the `outcome` label on the
 * `telemetry.export.count` self-metric, so they are part of the
 * dashboards' contract — don't rename them.
 */
enum ExportStatus: string
{
    /** The backend took everything. */
    case Ok = 'ok';

    /** The backend took the batch but rejected some data points. */
    case Partial = 'partial';

    /** Transient failure — 429/503, timeout, connection loss. */
    case Retryable = 'retryable';

    /** Permanent failure — 4xx, serialization. Retrying will not help. */
    case Failed = 'failed';

    /** The exporter itself threw; it never reported a result. */
    case Error = 'error';

    /** The exporter handles none of this batch's signals — nothing was sent. */
    case Skipped = 'skipped';

    /**
     * Whether the backend took the batch. Partial counts: the request was
     * accepted, and the rejected data points are reported separately.
     */
    public function accepted(): bool
    {
        return $this === self::Ok || $this === self::Partial;
    }
}
