<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Exceptions;

final class ApcuUnavailable extends TelemetryException
{
    public function __construct()
    {
        parent::__construct(
            'The apcu metric store requires the APCu extension (and apc.enable_cli=1 for CLI processes). '.
            'Install ext-apcu or switch TELEMETRY_STORE to "redis".'
        );
    }
}
