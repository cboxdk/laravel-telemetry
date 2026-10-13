<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation\Process;

use Closure;
use Illuminate\Process\Factory;
use Illuminate\Process\PendingProcess;

/**
 * The `Process` facade's factory, handing out pending processes that carry
 * the current trace context into the child's environment.
 *
 * Bound in place of the framework's factory only when nothing else has
 * bound it — an app or package with its own factory keeps it, and simply
 * goes without propagation.
 */
final class TracedProcessFactory extends Factory
{
    /**
     * @param  Closure(): array<string, string>  $carrier
     */
    public function __construct(private readonly Closure $carrier) {}

    /**
     * @return PendingProcess
     */
    public function newPendingProcess()
    {
        return (new TracedPendingProcess($this, $this->carrier))->withFakeHandlers($this->fakeHandlers);
    }
}
