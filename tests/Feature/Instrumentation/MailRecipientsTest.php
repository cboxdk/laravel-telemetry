<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Instrumentation\MailInstrumentation;
use Cbox\Telemetry\Tracing\Span;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Mail;

/**
 * Swap the boot-time instrumentation for one built the way the provider
 * builds it with instrument.mail_recipients on or off.
 */
function rearmMail(bool $recipients): void
{
    $events = app(Dispatcher::class);
    $events->forget(MessageSending::class);
    $events->forget(MessageSent::class);

    (new MailInstrumentation(app(), $recipients))->register($events);
}

beforeEach(function () {
    Telemetry::fake();
    config()->set('mail.default', 'array');
});

function sendToThree(): void
{
    Mail::raw('Hi', fn ($m) => $m->to(['ann@example.test', 'bo@example.test'])->cc('cc@example.test')->bcc('audit@example.test')->subject('Invoice'));
}

it('keeps addresses off the span unless asked to', function () {
    sendToThree();

    Telemetry::assertSpanRecorded('mail.send', fn (Span $s): bool => $s->attributes()['mail.recipients'] === 2
        && ! array_key_exists('mail.to', $s->attributes())
        && ! array_key_exists('mail.cc', $s->attributes())
        && ! array_key_exists('mail.bcc', $s->attributes()));
});

it('records who a message went to when asked, field by field', function () {
    rearmMail(recipients: true);

    sendToThree();

    Telemetry::assertSpanRecorded('mail.send', fn (Span $s): bool => $s->attributes()['mail.to'] === 'ann@example.test, bo@example.test'
        && $s->attributes()['mail.cc'] === 'cc@example.test'
        && $s->attributes()['mail.bcc'] === 'audit@example.test');
});

it('leaves out a field the message does not use', function () {
    rearmMail(recipients: true);

    Mail::raw('Hi', fn ($m) => $m->to('ann@example.test')->subject('Hi'));

    Telemetry::assertSpanRecorded('mail.send', fn (Span $s): bool => $s->attributes()['mail.to'] === 'ann@example.test'
        && ! array_key_exists('mail.cc', $s->attributes()));
});
