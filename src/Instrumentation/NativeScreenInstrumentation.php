<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\SpanStatus;
use Closure;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Screen instrumentation for NativePHP for Mobile v4 (SuperNative).
 *
 * ## Why this one is opt-in
 *
 * A native screen is not a request. `NativeRouter::start()` enters a loop
 * that calls `$component->runLoop()`, and that loop holds one request open
 * for as long as the user stays on the screen — so `Kernel::terminate()`
 * never fires between interactions and the per-request flush the rest of
 * this package depends on never happens. Something has to take its place.
 *
 * NativePHP v4 has no events and no container hook for the screen
 * lifecycle: `NativeRouter::createComponent()` does `new $class`, and both
 * the router and the component are constructed directly. So there is
 * nothing to listen to, and nothing to decorate. Until upstream gains
 * lifecycle events, the app has to hand us the two moments itself — from
 * one shared base class, which is about ten lines:
 *
 *     abstract class Screen extends NativeComponent
 *     {
 *         public function runLoop(): void
 *         {
 *             $this->telemetry()->aroundScreen(static::class, fn () => parent::runLoop());
 *         }
 *
 *         protected function dispatch(array $event): void
 *         {
 *             $this->telemetry()->aroundInteraction(static::class, 'screen.interaction', $event, fn () => parent::dispatch($event));
 *         }
 *
 *         protected function dispatchNativeEvent(array $event): void
 *         {
 *             $this->telemetry()->aroundInteraction(static::class, 'screen.native_event', $event, fn () => parent::dispatchNativeEvent($event));
 *         }
 *
 *         private function telemetry(): NativeScreenInstrumentation
 *         {
 *             return app(NativeScreenInstrumentation::class);
 *         }
 *     }
 *
 * `runLoop()`, `dispatch()` and `dispatchNativeEvent()` are NativePHP's
 * own internals, never anything an app defines, so overriding them in a
 * base class collides with nothing. `mount()` and `unmount()` are left
 * alone on purpose: a screen that defines its own `mount()` would
 * override the base class's, and instrumentation that silently stops
 * working on some screens is worse than instrumentation that was never
 * there.
 */
final class NativeScreenInstrumentation
{
    public function __construct(private readonly Container $container) {}

    /**
     * One screen visit. The flush on the way out is the last chance to
     * ship anything before the user navigates away and the component is
     * dropped.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function aroundScreen(string $screen, Closure $work): mixed
    {
        if (! $this->instrumenting()) {
            return $work();
        }

        $name = $this->screenName($screen);
        $startedAt = microtime(true);

        FailSafe::guard(function () use ($name): void {
            $telemetry = $this->telemetry();

            $telemetry->event('screen.view', ['screen.name' => $name]);
            $telemetry->counter('screen.views', 'Native screen views')->inc(1, ['screen' => $name]);
        });

        try {
            return $work();
        } finally {
            FailSafe::guard(function () use ($name, $startedAt): void {
                $this->telemetry()
                    ->histogram('screen.view.duration', description: 'Time spent on a native screen', unit: 'ms')
                    ->record((microtime(true) - $startedAt) * 1000, ['screen' => $name]);

                $this->telemetry()->flush();
            });
        }
    }

    /**
     * A tap, a text change, a photo coming back from the bridge —
     * whatever the user just did and is now waiting on.
     *
     * Each interaction is its own trace root, ended and flushed on the
     * spot. Deliberately NOT a child of a screen-wide span: that span
     * would stay open for minutes and never reach an exporter.
     *
     * The work itself runs outside FailSafe — telemetry swallowing the
     * app's own exception would be far worse than losing a span.
     *
     * @template T
     *
     * @param  string  $type  "interaction" (a tap, a keystroke) or
     *                        "native_event" (a bridge callback). Becomes
     *                        the span name `screen.{type}`.
     * @param  array<array-key, mixed>  $event
     * @param  Closure(): T  $work
     * @return T
     */
    public function aroundInteraction(string $screen, string $type, array $event, Closure $work): mixed
    {
        if (! $this->instrumenting()) {
            return $work();
        }

        $name = $this->screenName($screen);

        $span = FailSafe::guard(fn (): Span => $this->telemetry()->span(
            'screen.'.$type,
            attributes: [
                'screen.name' => $name,
                'screen.event.type' => $this->eventType($event),
            ],
            kind: SpanKind::Internal,
        ));

        $failed = false;

        try {
            return $work();
        } catch (Throwable $e) {
            $failed = true;

            FailSafe::guard(function () use ($span, $e): void {
                $span?->recordException($e);
                $span?->setStatus(SpanStatus::Error);
            });

            throw $e;
        } finally {
            FailSafe::guard(function () use ($span, $name, $type, $failed): void {
                if ($span !== null) {
                    $span->end();

                    $this->telemetry()
                        ->histogram('screen.interaction.duration', description: 'Native screen interaction duration', unit: 'ms')
                        ->record($span->durationMs(), ['screen' => $name, 'type' => $type]);
                }

                if ($failed) {
                    $this->telemetry()
                        ->counter('screen.interactions.failed', 'Native screen interactions that threw')
                        ->inc(1, ['screen' => $name]);
                }

                // The terminable-middleware analogue: on a native screen
                // this is the only moment where a trace is complete.
                $this->telemetry()->flush();
                $this->telemetry()->resetContext();
            });
        }
    }

    /**
     * The screen's short class name — stable, low cardinality, and it
     * reads the way the route registration does.
     */
    private function screenName(string $screen): string
    {
        $position = strrpos($screen, '\\');

        return $position === false ? $screen : substr($screen, $position + 1);
    }

    /**
     * @param  array<array-key, mixed>  $event
     */
    private function eventType(array $event): string
    {
        $type = $event['type'] ?? null;

        return is_scalar($type) ? (string) $type : 'unknown';
    }

    /**
     * `instrument.native_screens` is read per call, not memoized: a
     * screen already on the stack must honour a toggle flipped underneath
     * it (a consent prompt answered mid-session is exactly that).
     */
    private function instrumenting(): bool
    {
        return FailSafe::guard(function (): bool {
            $config = $this->container->make('config');

            return (bool) $config->get('telemetry.enabled')
                && (bool) $config->get('telemetry.instrument.native_screens', true);
        }) ?? false;
    }

    /**
     * Resolved per call rather than held, so Telemetry::fake() swaps take
     * effect for a screen that is already on the stack.
     */
    private function telemetry(): TelemetryManager
    {
        return $this->container->make(TelemetryManager::class);
    }
}
