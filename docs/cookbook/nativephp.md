---
title: 'Cookbook: NativePHP (mobile & desktop)'
description: Telemetry from a Laravel app running on someone's phone or laptop
weight: 6
---

# Cookbook: NativePHP

Verified against `nativephp/mobile` v4 (the SuperNative line, PHP `^8.4`)
and `nativephp/desktop` v2.

Both targets break the same two assumptions the rest of this package is
built on: there is no Redis and no APCu, and nothing can scrape a
Prometheus endpoint on a device you don't control. Desktop breaks nothing
else. Mobile breaks one more, and it is the interesting one — see
[Mobile](#mobile-v4) below.

## What you get

The payoff is the trace that crosses the device boundary. Outbound HTTP is
already instrumented and already propagates W3C traceparent, so a tap on a
phone and the SQL query it caused on your server land in one waterfall.
Nothing extra is needed for that — it falls out of turning telemetry on.

## Desktop (v2)

Desktop runs an embedded PHP server behind Electron, so there is a real
request cycle: `TraceRequest`, queries, cache, queue and command
instrumentation all work unchanged. Only storage and export need
answering.

```dotenv
TELEMETRY_STORE=sqlite
TELEMETRY_PROMETHEUS_ENABLED=false
TELEMETRY_EXPORTERS=otlp
TELEMETRY_OTLP_SPOOL=true
TELEMETRY_OTLP_SPOOL_DRIVER=sqlite
```

SQLite rather than `array` is not a preference here — a desktop app
typically runs its queue worker and scheduler as separate PHP processes,
and they write the same series as the server. See
[decision 0001](../decisions/0001-metric-state-on-single-process-runtimes.md).

The spool matters because a laptop closes mid-request. Entries survive in
`storage/framework/telemetry-spool.sqlite` and ship on the next run of
`telemetry:flush`.

## Mobile (v4)

Same config as desktop. The difference is what a "request" means.

`NativeRouter::start()` enters a loop that calls `$component->runLoop()`,
and that loop **holds one request open for as long as the user stays on
the screen**, blocking between interactions. So `Kernel::terminate()` never
fires while someone is using your app, and the per-request flush the rest
of this package relies on never happens. Left alone, a native screen
records telemetry that is never shipped.

`NativeScreenInstrumentation` puts the flush back on the only boundary
that exists: the interaction. NativePHP v4 dispatches no events for the
screen lifecycle and constructs both the router and the component with
`new`, so there is nothing to listen to and nothing to decorate — the app
has to hand us the moments itself. One shared base class does it for every
screen:

```php
use Cbox\Telemetry\Instrumentation\NativeScreenInstrumentation;
use Native\Mobile\Edge\NativeComponent;

abstract class Screen extends NativeComponent
{
    public function runLoop(): void
    {
        $this->telemetry()->aroundScreen(static::class, fn () => parent::runLoop());
    }

    protected function dispatch(array $event): void
    {
        $this->telemetry()->aroundInteraction(
            static::class, 'interaction', $event, fn () => parent::dispatch($event),
        );
    }

    protected function dispatchNativeEvent(array $event): void
    {
        $this->telemetry()->aroundInteraction(
            static::class, 'native_event', $event, fn () => parent::dispatchNativeEvent($event),
        );
    }

    private function telemetry(): NativeScreenInstrumentation
    {
        return app(NativeScreenInstrumentation::class);
    }
}
```

Point your screens at `Screen` instead of `NativeComponent` and you get:

| Signal | What it tells you |
| --- | --- |
| `screen.interaction` span | How long the user waited after a tap, with the queries and HTTP calls it caused nested inside |
| `screen.native_event` span | The same for bridge callbacks — a photo returning, a scan resolving |
| `screen.interaction.duration` | Interaction latency by screen |
| `screen.interactions.failed` | Interactions that threw |
| `screen.view` event + `screen.views` | Navigation, screen by screen |
| `screen.view.duration` | Time spent per screen |

Spans carry `screen.name` and `screen.event.type`; the duration histograms
are labelled `{screen}` and `{screen,type}`. The whole integration sits
behind `instrument.native_screens` — turn it off and those forwards pass
straight through, which is how a consent prompt answered mid-session takes
effect without a restart.

Each interaction is its own trace root, flushed on the spot. It is
deliberately not a child of a screen-wide span: that span would stay open
for minutes and never reach an exporter.

### What is deliberately not instrumented

`mount()`, `onResume()` and `unmount()` are yours to override, and a
screen that defines its own `mount()` would silently replace an
instrumented base-class one. Instrumentation that quietly stops working on
some screens is worse than instrumentation that was never there, so these
are left alone. The render frame is out of reach for a related reason:
`NativeComponent::renderToElement()` is private.

Both gaps need upstream lifecycle events to close properly.

### The persistent runtime

NativePHP boots the app once and dispatches through the same container,
which is Octane's problem in a smaller box. The package registers a reset
on `Native\Mobile\Runtime::onReset()` automatically when NativePHP is
installed — no configuration. Note it only covers the web/Livewire path;
SuperNative screens never reach `Runtime::dispatch()`, which is why
`NativeScreenInstrumentation` resets context per interaction itself.

## Before you ship this

**The app is a public client.** An OTLP token compiled into a bundle can
be extracted from it — treat anything you put in `TELEMETRY_OTLP_HEADERS`
as published. Send device telemetry through an ingest gateway that issues
per-install tokens and can revoke them, not straight to your collector.

**This is end-user data.** Device telemetry is personal data in a way
server telemetry usually isn't. Gate it behind consent — `telemetry.enabled`
is a runtime config value, and disabled means no listeners, no-op
instruments and no providers booted, so an opted-out user costs nothing.
