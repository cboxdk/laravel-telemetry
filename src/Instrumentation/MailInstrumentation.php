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
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Mail\Transport\LogTransport;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/**
 * Mail instrumentation: a client span per sent message (transport time
 * is often the slowest part of a request) plus mail.sent and mail.failed
 * counters. A send the transport refused ends its span as an error with the
 * exception on it (see {@see ObservedTransport}).
 */
final class MailInstrumentation implements ManagesRequestState
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

            $this->observe($event->data['mailer'] ?? null);
        });
    }

    /**
     * Put the sending mailer's transport behind an {@see ObservedTransport},
     * once, so a send it refuses is seen. MessageSending fires just before
     * the transport is called, on the mailer Laravel caches by name, so the
     * wrap takes effect for this very send.
     *
     * The array and log transports cannot fail and are left as they are:
     * tests read sent mail off them by their own class.
     */
    private function observe(mixed $mailer): void
    {
        if (! is_string($mailer) || ! $this->container->bound('mail.manager')) {
            return;
        }

        $manager = $this->container->make('mail.manager');

        // Mail::fake() swaps the manager: nothing reaches a transport then.
        if (! $manager instanceof MailManager) {
            return;
        }

        $instance = $manager->mailer($mailer);

        if (! $instance instanceof Mailer) {
            return;
        }

        $transport = $instance->getSymfonyTransport();

        if ($transport instanceof ObservedTransport || $transport instanceof ArrayTransport || $transport instanceof LogTransport) {
            return;
        }

        $instance->setSymfonyTransport(new ObservedTransport($transport, $this->failed(...)));
    }

    private function failed(RawMessage $message, Throwable $exception): void
    {
        FailSafe::guard(function () use ($message, $exception) {
            // The transport is handed the message MessageSending carried, so
            // identity finds its span (the clone happens inside the transport).
            $span = $this->pullSpanFor($message);

            if ($span !== null) {
                $span->recordException($exception);
                $span->end();
            }

            $this->telemetry()
                ->counter('mail.failed', 'Mail messages the transport refused')
                ->inc();
        });
    }

    private function sent(MessageSent $event): void
    {
        FailSafe::guard(function () use ($event) {
            $span = $this->pullSpanFor($event->sent->getOriginalMessage());

            if ($span !== null) {
                $span->setStatus(SpanStatus::Ok);
                $span->end();
            }

            $this->telemetry()
                ->counter('mail.sent', 'Mail messages sent')
                ->inc();
        });
    }

    /**
     * Find the span for a message the transport may already have copied.
     *
     * Symfony's AbstractTransport::send() does `$message = clone $message` on
     * its first line and SentMessage keeps THAT clone as its "original", so on
     * those transports the object reaching MessageSent is not the one
     * MessageSending carried and identity misses. (The array and log
     * transports do preserve it, so identity is tried first and usually wins.)
     * The span was then never ended, and because it stayed on the tracer stack
     * every later span in the request was parented under the mail call.
     *
     * The fallback takes the NEWEST open mail span, not the oldest. Sends
     * nest — a MessageSending listener can send its own mail — and they nest
     * strictly, so the innermost completes first. LIFO closes that one.
     * Oldest-first would have closed the OUTER span from the inner send,
     * before the outer message had even reached its transport.
     *
     * There is no exact key available: Laravel sets no Message-ID before
     * dispatching MessageSending, so once a transport clones there is nothing
     * shared to match on. LIFO is therefore a heuristic, and it has a known
     * boundary — if an inner send's transport THROWS and the caller swallows
     * it, that abandoned inner span stays newest and the outer send's
     * completion pops it, reporting the failed inner send as Ok and leaving the
     * outer one open. The alternative is keying on identity alone, which misses
     * on every SMTP send and leaks a span each time. A rare mis-pairing beats a
     * guaranteed leak, but it is a trade, not a correct answer.
     */
    private function pullSpanFor(object $message): ?Span
    {
        $key = spl_object_id($message);

        if (! isset($this->sending[$key])) {
            $key = array_key_last($this->sending);
        }

        if ($key === null) {
            return null;
        }

        $span = $this->sending[$key];
        unset($this->sending[$key]);

        return $span;
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
