<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Closure;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/**
 * A mail transport that tells the mail instrumentation when a send threw.
 *
 * Laravel fires MessageSending before the transport and MessageSent after
 * it, but nothing when the transport throws — an SMTP refusal, a provider
 * outage — so a failed send left its span open, to be discarded at the end
 * of the request: the failures were the sends nobody could see. This sits
 * between the mailer and its transport, passes everything through, and on a
 * throw reports it before rethrowing. Anything else asked of it goes to the
 * transport it wraps.
 *
 * @mixin TransportInterface
 */
final class ObservedTransport implements TransportInterface
{
    /**
     * @param  Closure(RawMessage, Throwable): void  $failed
     */
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly Closure $failed,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        try {
            return $this->transport->send($message, $envelope);
        } catch (Throwable $exception) {
            ($this->failed)($message, $exception);

            throw $exception;
        }
    }

    /** The transport doing the sending. */
    public function transport(): TransportInterface
    {
        return $this->transport;
    }

    public function __toString(): string
    {
        return (string) $this->transport;
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->transport->{$method}(...$arguments);
    }
}
