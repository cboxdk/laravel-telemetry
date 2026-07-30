<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Exceptions;

final class SqliteUnavailable extends TelemetryException
{
    public function __construct()
    {
        parent::__construct(
            'The sqlite metric store requires the pdo_sqlite extension. '.
            'Install ext-pdo_sqlite or switch TELEMETRY_STORE to "redis".'
        );
    }
}
