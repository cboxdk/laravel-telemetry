<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Events\BatchCanceled;
use Illuminate\Bus\Events\BatchDispatched;
use Illuminate\Bus\Events\BatchFinished;
use Illuminate\Bus\Events\BatchStarted;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Job batch lifecycle counters — bus.batches{event, name}. Individual
 * batch jobs are already covered by the queue instrumentation; this
 * adds the batch-level story (dispatched vs finished vs canceled).
 */
final class BusInstrumentation
{
    private const EVENTS = [
        BatchDispatched::class => 'dispatched',
        BatchStarted::class => 'started',
        BatchFinished::class => 'finished',
        BatchCanceled::class => 'canceled',
    ];

    public function __construct(private readonly Container $container) {}

    public function register(Dispatcher $events): void
    {
        foreach (self::EVENTS as $class => $outcome) {
            if (! class_exists($class)) {
                continue; // older framework versions lack some of these
            }

            $events->listen($class, function (object $event) use ($outcome) {
                FailSafe::guard(function () use ($event, $outcome) {
                    /** @var Batch $batch */
                    $batch = $event->batch; // @phpstan-ignore property.notFound

                    $this->container->make(TelemetryManager::class)
                        ->counter('bus.batches', 'Job batch lifecycle events')
                        ->inc(1, ['event' => $outcome, 'name' => $batch->name !== '' ? $batch->name : 'unnamed']);
                });
            });
        }
    }
}
