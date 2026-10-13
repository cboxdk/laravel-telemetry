<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation\Process;

use Closure;
use Illuminate\Process\Factory;
use Illuminate\Process\PendingProcess;
use Symfony\Component\Process\Process;

/**
 * A pending process that starts its child with `TRACEPARENT` set.
 *
 * Environment variables are OpenTelemetry's carrier for exactly this
 * case — a process starting a process — so the child artisan command
 * (or any OTel-instrumented program) continues the trace as a child
 * instead of opening a detached root. The variable is added when the
 * Symfony process is BUILT, not when the pending process is created:
 * `env([...])` replaces the whole array, so anything set earlier would be
 * lost, and the span that is current at `run()` is the right parent.
 *
 * A value the caller set explicitly wins.
 */
final class TracedPendingProcess extends PendingProcess
{
    /**
     * @param  Closure(): array<string, string>  $carrier
     */
    public function __construct(Factory $factory, private readonly Closure $carrier)
    {
        parent::__construct($factory);
    }

    /**
     * @param  array<array-key, string>|string|null  $command
     * @return Process
     */
    protected function toSymfonyProcess(array|string|null $command)
    {
        $process = parent::toSymfonyProcess($command);

        $carried = ($this->carrier)();

        if ($carried === []) {
            return $process;
        }

        $environment = $process->getEnv();

        foreach ($carried as $name => $value) {
            if (! array_key_exists($name, $environment)) {
                $environment[$name] = $value;
            }
        }

        return $process->setEnv($environment);
    }
}
