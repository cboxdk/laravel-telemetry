<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

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

/**
 * Notification instrumentation: a client span per delivery and a
 * notifications.sent{channel, notification} counter — channel and class
 * are bounded, so they're safe labels.
 */
final class NotificationInstrumentation
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
        $events->listen(NotificationFailed::class, function (NotificationFailed $event) {
            FailSafe::guard(fn () => $this->telemetry()
                ->counter('notifications.failed', 'Notifications that failed to send')
                ->inc(1, ['channel' => $event->channel, 'notification' => class_basename($event->notification)]));
        });
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
            $key = $this->key($event->notification, $event->channel);
            $span = $this->sending[$key] ?? null;
            unset($this->sending[$key]);

            if ($span !== null) {
                $span->setStatus(SpanStatus::Ok);
                $span->end();
            }

            $this->telemetry()
                ->counter('notifications.sent', 'Notifications sent by channel')
                ->inc(1, [
                    'channel' => $event->channel,
                    'notification' => $event->notification::class,
                ]);
        });
    }

    private function key(object $notification, string $channel): string
    {
        return spl_object_id($notification).':'.$channel;
    }
}
