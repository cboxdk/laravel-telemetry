<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\ModelsPruned;
use Illuminate\Support\Str;

/**
 * Eloquent model observability:
 *
 * - `model.hydrations` root-span tally — THE N+1 smell: "this request
 *   hydrated 1 400 models" jumps out of the trace even when every
 *   individual query was fast.
 * - `models.events{model, event}` counter for writes (created/updated/
 *   deleted/restored/force_deleted) — write rates per model, bounded
 *   labels (class basenames).
 * - `models.pruned{model}` counter from the pruner.
 *
 * Retrievals are deliberately tally-only: `eloquent.retrieved` fires per
 * hydrated INSTANCE and would be an absurd counter cardinality/volume.
 */
final class ModelInstrumentation
{
    private const WRITE_EVENTS = ['created', 'updated', 'deleted', 'restored', 'forceDeleted'];

    public function __construct(private readonly Container $container) {}

    public function register(Dispatcher $events): void
    {
        $events->listen('eloquent.retrieved: *', function () {
            FailSafe::guard(fn () => $this->telemetry()->tracer()->bumpStat('model.hydrations', 1));
        });

        foreach (self::WRITE_EVENTS as $write) {
            $events->listen("eloquent.{$write}: *", function (string $eventName) use ($write) {
                FailSafe::guard(function () use ($eventName, $write) {
                    // "eloquent.created: App\Models\Order" → "Order"
                    $model = class_basename(substr($eventName, strpos($eventName, ': ') + 2));

                    $this->telemetry()->counter('models.events', 'Eloquent write events by model')
                        ->inc(1, ['model' => $model, 'event' => Str::snake($write)]);
                });
            });
        }

        $events->listen(ModelsPruned::class, function (ModelsPruned $event) {
            FailSafe::guard(fn () => $this->telemetry()->counter('models.pruned', 'Models removed by the pruner')
                ->inc($event->count, ['model' => class_basename($event->model)]));
        });
    }

    private function telemetry(): TelemetryManager
    {
        return $this->container->make(TelemetryManager::class);
    }
}
