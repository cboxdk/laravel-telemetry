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
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;

/**
 * Mail instrumentation: a client span per sent message (transport time
 * is often the slowest part of a request) plus a mail.sent counter.
 */
final class MailInstrumentation
{
    /** @var array<int, Span> keyed by message object id */
    private array $sending = [];

    public function __construct(private readonly Container $container) {}

    private function telemetry(): TelemetryManager
    {
        return $this->container->make(TelemetryManager::class);
    }

    public function register(Dispatcher $events): void
    {
        $events->listen(MessageSending::class, $this->sending(...));
        $events->listen(MessageSent::class, $this->sent(...));
    }

    private function sending(MessageSending $event): void
    {
        FailSafe::guard(function () use ($event) {
            $this->sending[spl_object_id($event->message)] = $this->telemetry()->tracer()->startSpan(
                'mail.send',
                SpanKind::Client,
                [
                    'mail.subject' => (string) $event->message->getSubject(),
                    'mail.recipients' => count($event->message->getTo()),
                ],
            );
        });
    }

    private function sent(MessageSent $event): void
    {
        FailSafe::guard(function () use ($event) {
            $span = $this->sending[spl_object_id($event->sent->getOriginalMessage())] ?? null;
            unset($this->sending[spl_object_id($event->sent->getOriginalMessage())]);

            if ($span !== null) {
                $span->setStatus(SpanStatus::Ok);
                $span->end();
            }

            $this->telemetry()
                ->counter('mail.sent', 'Mail messages sent')
                ->inc();
        });
    }
}
