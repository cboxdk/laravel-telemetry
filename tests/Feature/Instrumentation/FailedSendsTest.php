<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Instrumentation\ObservedTransport;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanStatus;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/** A transport whose server refuses every message, as an SMTP outage does. */
final class RefusingTransport implements TransportInterface
{
    public int $pings = 0;

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        throw new TransportException('Expected response code "250" but got code "554", with message "554 5.7.1 Relay access denied".');
    }

    public function ping(): string
    {
        $this->pings++;

        return 'pong';
    }

    public function __toString(): string
    {
        return 'refusing://';
    }
}

beforeEach(function () {
    Telemetry::fake();
    Mail::extend('refusing', fn () => new RefusingTransport);
    config()->set('mail.mailers.refusing', ['transport' => 'refusing']);
});

it('ends a mail the transport refused as a failed send that says why', function () {
    $root = Telemetry::tracer()->startSpan('POST /checkout');

    expect(fn () => Mail::mailer('refusing')->raw('Hi', fn ($m) => $m->to('a@example.test')->subject('Receipt')))
        ->toThrow(TransportException::class);

    // Not left open as the ambient parent of everything after it.
    expect(Telemetry::currentSpan()?->name)->toBe('POST /checkout');
    $root->end();

    Telemetry::assertSpanRecorded('mail.send', fn (Span $s): bool => $s->status() === SpanStatus::Error
        && str_contains((string) $s->statusDescription(), 'Relay access denied')
        && $s->attributes()['mail.subject'] === 'Receipt'
        && ($s->attributes()['error.type'] ?? null) === TransportException::class
        && collect($s->events())->contains(fn ($e) => $e->name === 'exception'));
    Telemetry::assertCounterIncremented('mail.failed');
    Telemetry::assertCounterNotIncremented('mail.sent');
});

it('leaves the transport doing the sending, and its own methods reachable', function () {
    try {
        Mail::mailer('refusing')->raw('Hi', fn ($m) => $m->to('a@example.test'));
    } catch (TransportException) {
    }

    $transport = Mail::mailer('refusing')->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(ObservedTransport::class)
        ->and($transport->transport())->toBeInstanceOf(RefusingTransport::class)
        ->and((string) $transport)->toBe('refusing://')
        ->and($transport->ping())->toBe('pong');

    // Wrapped once, not once per send.
    try {
        Mail::mailer('refusing')->raw('Again', fn ($m) => $m->to('a@example.test'));
    } catch (TransportException) {
    }

    expect(Mail::mailer('refusing')->getSymfonyTransport()->transport())->toBeInstanceOf(RefusingTransport::class);
});

it('leaves the array transport alone, so tests still read mail off it', function () {
    config()->set('mail.default', 'array');

    Mail::raw('Hi', fn ($m) => $m->to('a@example.test')->subject('Welcome'));

    expect(Mail::mailer('array')->getSymfonyTransport())->toBeInstanceOf(ArrayTransport::class);
    Telemetry::assertSpanRecorded('mail.send', fn (Span $s): bool => $s->status() === SpanStatus::Ok);
    Telemetry::assertCounterIncremented('mail.sent');
});

it('puts a failed notification\'s exception on its span', function () {
    $notification = new class extends Notification {};
    $root = Telemetry::tracer()->startSpan('POST /checkout');

    Event::dispatch(new NotificationSending(new stdClass, $notification, 'database'));
    Event::dispatch(new NotificationFailed(new stdClass, $notification, 'database', ['exception' => new RuntimeException('no such table: notifications')]));

    $root->end();

    Telemetry::assertSpanRecorded('notification.send', fn (Span $s): bool => $s->status() === SpanStatus::Error
        && $s->statusDescription() === 'no such table: notifications'
        && ($s->attributes()['error.type'] ?? null) === RuntimeException::class);
});
