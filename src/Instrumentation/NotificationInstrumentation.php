<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Contracts\ManagesRequestState;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\SpanStatus;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Events\NotificationSkipped;

/**
 * Notification instrumentation: a client span per delivery and a
 * notifications.sent{channel, notification} counter — channel and class
 * are bounded, so they're safe labels.
 */
final class NotificationInstrumentation implements ManagesRequestState
{
    /** @var array<string, Span> keyed by notification object id + channel */
    private array $sending = [];

    public function __construct(private readonly Container $container) {}

    private function telemetry(): TelemetryManager
    {
        return $this->container->make(TelemetryManager::class);
    }

    public function register(Dispatcher $events): void
    {
        $events->listen(NotificationSending::class, $this->sending(...));
        $events->listen(NotificationSent::class, $this->sent(...));
        $events->listen(NotificationFailed::class, $this->failed(...));
        $events->listen(NotificationSkipped::class, $this->skipped(...));
    }

    private function sending(NotificationSending $event): void
    {
        FailSafe::guard(function () use ($event) {
            $this->sending[$this->key($event->notification, $event->channel)] = $this->telemetry()->tracer()->startSpan(
                'notification.send',
                SpanKind::Client,
                [
                    'notification.class' => $event->notification::class,
                    'notification.channel' => $event->channel,
                ],
            );
        });
    }

    private function sent(NotificationSent $event): void
    {
        FailSafe::guard(function () use ($event) {
            $this->close($event->notification, $event->channel, SpanStatus::Ok);

            $this->telemetry()
                ->counter('notifications.sent', 'Notifications sent by channel')
                ->inc(1, [
                    'channel' => $event->channel,
                    'notification' => class_basename($event->notification),
                ]);
        });
    }

    /**
     * A delivery that threw.
     *
     * Closing the span matters more than the counter. `NotificationSending`
     * pushes it onto the tracer's context stack, and only ending it pops
     * it — so a failure used to leave `notification.send` as the ambient
     * parent, and everything the request did afterwards became a child of
     * the delivery that had already failed. The shape of the trace was
     * rewritten by an exception somewhere else entirely.
     */
    private function failed(NotificationFailed $event): void
    {
        FailSafe::guard(function () use ($event) {
            $this->close($event->notification, $event->channel, SpanStatus::Error);

            $this->telemetry()
                ->counter('notifications.failed', 'Notifications that failed to send')
                ->inc(1, [
                    'channel' => $event->channel,
                    'notification' => class_basename($event->notification),
                ]);
        });
    }

    /**
     * A delivery the notification itself called off (`shouldSend`).
     *
     * Nothing was sent, so there is no client span to report — but one is
     * open, and leaving it would parent the rest of the request under a
     * delivery that never happened. Discarded rather than ended: a span
     * measuring a decision not to act is not a measurement.
     */
    private function skipped(NotificationSkipped $event): void
    {
        FailSafe::guard(function () use ($event) {
            $key = $this->key($event->notification, $event->channel);
            $span = $this->sending[$key] ?? null;
            unset($this->sending[$key]);

            if ($span instanceof Span) {
                $this->telemetry()->tracer()->discardSpan($span);
            }

            $this->telemetry()
                ->counter('notifications.skipped', 'Notifications a shouldSend() call declined')
                ->inc(1, [
                    'channel' => $event->channel,
                    'notification' => class_basename($event->notification),
                ]);
        });
    }

    private function close(object $notification, string $channel, SpanStatus $status): void
    {
        $key = $this->key($notification, $channel);
        $span = $this->sending[$key] ?? null;
        unset($this->sending[$key]);

        if ($span instanceof Span) {
            $span->setStatus($status);
            $span->end();
        }
    }

    private function key(object $notification, string $channel): string
    {
        return spl_object_id($notification).':'.$channel;
    }

    public function flushRequestState(): void
    {
        // Dropping the map is not enough on its own: the spans stay on the
        // tracer's context stack, and the shutdown path ends everything still
        // open as an error that lasted until the process died. Discarded
        // properly instead — a missing span is the lesser evil, a span with the
        // wrong duration and status is a lie that reads as data.

        foreach ($this->sending as $span) {
            FailSafe::guard(fn () => $this->telemetry()->tracer()->discardSpan($span));
        }

        $this->sending = [];
    }
}
