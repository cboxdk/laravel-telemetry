<?php

declare(strict_types=1);

namespace Cbox\Telemetry;

use Cbox\Telemetry\Contracts\TelemetryProvider;
use Cbox\Telemetry\Metrics\Registry;
use Closure;

/**
 * Wraps a closure as a provider — backs Telemetry::contributes().
 */
final readonly class InlineProvider implements TelemetryProvider
{
    /**
     * @param  Closure(Registry): void  $register
     */
    public function __construct(
        private string $name,
        private Closure $register,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function register(Registry $registry): void
    {
        ($this->register)($registry);
    }
}
