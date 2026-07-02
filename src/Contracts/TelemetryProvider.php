<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Contracts;

use Cbox\Telemetry\Metrics\Registry;

/**
 * Implemented by packages that publish telemetry.
 *
 * Providers are registered lazily: register() is only invoked when telemetry
 * is enabled and a consumer (exporter or scrape) needs the instruments.
 */
interface TelemetryProvider
{
    /**
     * Globally unique provider name, e.g. "cbox.queue-metrics".
     */
    public function name(): string;

    /**
     * Declare instruments on the registry.
     */
    public function register(Registry $registry): void;
}
