<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\Span;
use Closure;
use Livewire\ComponentHook;

/**
 * Livewire component lifecycle, registered via `Livewire::componentHook()`.
 *
 * Livewire instantiates hooks itself (`new $hook`, no container), so
 * TelemetryManager is resolved lazily through the `app()` helper rather
 * than constructor injection.
 *
 * mount/hydrate have no "after" phase in the ComponentHook API — our
 * hook is one peer listener among several, not a wrapper around the
 * others — so they're counted, not timed. render/update/call DO wrap
 * the real work (Livewire calls our returned closure once the wrapped
 * phase finishes), so those get real detail spans, same decorator
 * shape as ViewInstrumentation's TracingEngine: gated on the current
 * span being sampled, root-span tally regardless.
 */
final class LivewireInstrumentation extends ComponentHook
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function mount(array $params, mixed $parent): void
    {
        FailSafe::guard(function (): void {
            $this->telemetry()->counter('livewire.components.mounted', 'Livewire components mounted')
                ->inc(1, ['livewire.component' => $this->componentName()]);
        });
    }

    public function hydrate(mixed $memo): void
    {
        FailSafe::guard(function (): void {
            $this->telemetry()->counter('livewire.components.hydrated', 'Livewire components hydrated on a subsequent request')
                ->inc(1, ['livewire.component' => $this->componentName()]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function render(mixed $view, array $data): Closure
    {
        $span = $this->startDetailSpan('livewire.render', [
            'livewire.component' => $this->componentName(),
        ]);

        return function () use ($span): void {
            FailSafe::guard(fn () => $span?->end());
        };
    }

    public function update(string $propertyName, string $fullPath, mixed $newValue): Closure
    {
        $span = $this->startDetailSpan('livewire.update', [
            'livewire.component' => $this->componentName(),
            'livewire.property' => $propertyName,
        ]);

        return function () use ($span): void {
            FailSafe::guard(fn () => $span?->end());
        };
    }

    /**
     * @param  array<int, mixed>  $params
     */
    public function call(string $method, array $params, Closure $returnEarly): Closure
    {
        $span = $this->startDetailSpan('livewire.call', [
            'livewire.component' => $this->componentName(),
            'livewire.method' => $method,
        ]);

        return function () use ($span): void {
            FailSafe::guard(fn () => $span?->end());
        };
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    private function startDetailSpan(string $name, array $attributes): ?Span
    {
        return FailSafe::guard(function () use ($name, $attributes): ?Span {
            $telemetry = $this->telemetry();

            $telemetry->tracer()->bumpStat("{$name}.count", 1);

            if ($telemetry->currentSpan()?->sampled !== true) {
                return null;
            }

            return $telemetry->tracer()->startSpan($name, attributes: $attributes)->markDetail();
        });
    }

    private function componentName(): string
    {
        $component = $this->component;

        return is_object($component) && method_exists($component, 'getName') ? (string) $component->getName() : 'unknown';
    }

    private function telemetry(): TelemetryManager
    {
        return app(TelemetryManager::class);
    }
}
