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
use Illuminate\Contracts\Events\Dispatcher;
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
 * ## Two halves, only one of them automatic
 *
 * **Screen views are automatic** wherever `nativephp/mobile` dispatches
 * the screen lifecycle events (`Native\Mobile\Events\Screen\*`, added
 * upstream in NativePHP/mobile-air#248). {@see register()} listens for
 * them behind a `class_exists` guard, exactly like the Horizon and
 * Pennant integrations, so an older NativePHP simply never arms it.
 *
 * **Interactions are not, and cannot be.** Those events cover
 * `mount()` / `onResume()` / `unmount()` only. A tap goes through
 * `NativeComponent::dispatch()`, which upstream does not announce — and
 * that is precisely where the flush has to happen, because the runloop
 * holds its request open across every interaction on the screen. So an
 * app that wants interaction spans still forwards two methods from one
 * shared base class:
 *
 *     abstract class Screen extends NativeComponent
 *     {
 *         protected function dispatch(array $event): void
 *         {
 *             $this->telemetry()->aroundInteraction(static::class, 'interaction', $event, fn () => parent::dispatch($event));
 *         }
 *
 *         protected function dispatchNativeEvent(array $event): void
 *         {
 *             $this->telemetry()->aroundInteraction(static::class, 'native_event', $event, fn () => parent::dispatchNativeEvent($event));
 *         }
 *
 *         private function telemetry(): NativeScreenInstrumentation
 *         {
 *             return app(NativeScreenInstrumentation::class);
 *         }
 *     }
 *
 * `dispatch()` and `dispatchNativeEvent()` are NativePHP's own internals,
 * never anything an app defines, so overriding them in a base class
 * collides with nothing. `mount()` and `unmount()` are deliberately NOT
 * forwarded this way even though it would work: a screen that defines its
 * own `mount()` would override the base class's, and instrumentation that
 * silently stops working on some screens is worse than instrumentation
 * that was never there. That is the gap the upstream events close.
 *
 * {@see aroundScreen()} remains for apps on a NativePHP without the
 * events; it steps aside on its own once `register()` has armed the
 * evented path, so a base class that still forwards `runLoop()` does not
 * double-count.
 */
final class NativeScreenInstrumentation
{
    /**
     * Upstream event names — referenced as strings; NativePHP is not a
     * dependency here. MOUNTED doubles as the "does this NativePHP have
     * the events" probe, so the provider can skip resolving this class
     * entirely on an install that has no use for it.
     */
    public const MOUNTED = 'Native\Mobile\Events\Screen\ScreenMounted';

    private const RESUMED = 'Native\Mobile\Events\Screen\ScreenResumed';

    private const UNMOUNTED = 'Native\Mobile\Events\Screen\ScreenUnmounted';

    private bool $lifecycleEvented = false;

    /** @var array<string, float> screen name => microtime of the visit that opened it */
    private array $openedAt = [];

    public function __construct(private readonly Container $container) {}

    /**
     * Arm the automatic half. No-op on a NativePHP without the lifecycle
     * events, which is every release up to and including 4.0.1.
     */
    public function register(Dispatcher $events): void
    {
        if (! class_exists(self::MOUNTED)) {
            return;
        }

        $this->lifecycleEvented = true;

        $events->listen(self::MOUNTED, fn (object $event) => $this->screenOpened($event, resumed: false));
        $events->listen(self::RESUMED, fn (object $event) => $this->screenOpened($event, resumed: true));
        $events->listen(self::UNMOUNTED, fn (object $event) => $this->screenClosed($event));
    }

    /**
     * A screen was pushed, or returned to. Both are a view.
     */
    private function screenOpened(object $event, bool $resumed): void
    {
        if (! $this->instrumenting()) {
            return;
        }

        FailSafe::guard(function () use ($event, $resumed): void {
            $screen = $this->screenName($this->attribute($event, 'component'));

            $this->openedAt[$screen] = microtime(true);

            $this->telemetry()->event('screen.view', array_filter([
                'screen.name' => $screen,
                'screen.uri' => $this->attribute($event, 'uri'),
                'screen.resumed' => $resumed ? 'true' : 'false',
            ], fn (?string $value): bool => $value !== null));

            // No `resumed` label: aroundScreen() cannot tell a push from a
            // resume, and one metric name carrying two different label sets
            // depending on which path recorded it is how dashboards break.
            // The distinction lives on the event, where detail belongs.
            $this->telemetry()->counter('screen.views', 'Native screen views')->inc(1, ['screen' => $screen]);
        });
    }

    /**
     * A screen left the stack. The last chance to ship anything it
     * recorded — the component is about to be dropped.
     */
    private function screenClosed(object $event): void
    {
        if (! $this->instrumenting()) {
            return;
        }

        FailSafe::guard(function () use ($event): void {
            $screen = $this->screenName($this->attribute($event, 'component'));
            $openedAt = $this->openedAt[$screen] ?? null;

            unset($this->openedAt[$screen]);

            if ($openedAt !== null) {
                $this->telemetry()
                    ->histogram('screen.view.duration', description: 'Time spent on a native screen', unit: 'ms')
                    ->record((microtime(true) - $openedAt) * 1000, ['screen' => $screen]);
            }

            $this->telemetry()->flush();
        });
    }

    /**
     * The events carry public string properties, but NativePHP is not a
     * dependency here — read them defensively rather than type-hinting a
     * class this package cannot see.
     */
    private function attribute(object $event, string $property): ?string
    {
        if (! property_exists($event, $property)) {
            return null;
        }

        $value = $event->{$property};

        return is_string($value) && $value !== '' ? $value : null;
    }

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
        // Once the upstream events are arming this, a base class that still
        // forwards runLoop() would count every view twice. Step aside — but
        // keep running the work, which is the caller's actual screen.
        if ($this->lifecycleEvented || ! $this->instrumenting()) {
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
    private function screenName(?string $screen): string
    {
        if ($screen === null || $screen === '') {
            return 'unknown';
        }

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
