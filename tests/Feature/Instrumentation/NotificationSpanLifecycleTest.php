<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanStatus;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSkipped;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Telemetry::fake();
    $this->root = Telemetry::tracer()->startSpan('checkout');
    $this->notification = new class extends Notification {};
});

it('does not leave a failed notification as the ambient parent', function () {
    // NotificationSending pushes a span onto the context stack, and only
    // ending it pops. A failure left `notification.send` current, so
    // everything the request did next became a child of the delivery that
    // had already failed — an exception in the mail driver quietly
    // rewriting the shape of the whole trace.
    Event::dispatch(new NotificationSending(new stdClass, $this->notification, 'mail'));
    Event::dispatch(new NotificationFailed(new stdClass, $this->notification, 'mail', []));

    expect(Telemetry::currentSpan()?->name)->toBe('checkout');

    $this->root->end();

    Telemetry::assertSpanRecorded('notification.send', fn (Span $s): bool => $s->status() === SpanStatus::Error);
    Telemetry::assertCounterIncremented('notifications.failed');
});

it('discards the span for a notification that was never sent', function () {
    Event::dispatch(new NotificationSending(new stdClass, $this->notification, 'mail'));
    Event::dispatch(new NotificationSkipped(new stdClass, $this->notification, 'mail'));

    expect(Telemetry::currentSpan()?->name)->toBe('checkout');

    $this->root->end();

    // A span measuring a decision not to act is not a measurement.
    Telemetry::assertSpanNotRecorded('notification.send');
    Telemetry::assertCounterIncremented('notifications.skipped');
});
