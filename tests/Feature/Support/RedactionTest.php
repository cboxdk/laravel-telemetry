<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Testing\CollectingExporter;

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);
});

function flushedSpans(CollectingExporter $collector): array
{
    return collect($collector->batches())->flatMap(fn ($batch) => $batch->spans)->all();
}

it('redacts sensitive span attributes at flush', function () {
    Telemetry::span('payment.charge', fn () => true, [
        'stripe.api_key' => 'sk_live_abc123',
        'order.id' => '42',
    ]);
    Telemetry::flush();

    $attributes = flushedSpans($this->collector)[0]->attributes();

    expect($attributes['stripe.api_key'])->toBe('[REDACTED]')
        ->and($attributes['order.id'])->toBe('42');
});

it('scrubs secrets from exception messages on span events', function () {
    try {
        Telemetry::span('http.call', function () {
            throw new RuntimeException('upstream rejected Bearer abcdef1234567890abcdef');
        });
    } catch (RuntimeException) {
    }

    Telemetry::flush();

    $event = flushedSpans($this->collector)[0]->events()[0];

    expect($event->attributes['exception.message'])->toBe('upstream rejected Bearer [REDACTED]');
});

it('redacts event attributes and applies the custom hook', function () {
    Telemetry::redactUsing(fn (string $key, string $value) => str_ends_with($key, '.cpr') ? '[CPR]' : null);

    Telemetry::event('user.signup', ['customer.cpr' => '010203-1234', 'user.password' => 'hunter2', 'plan' => 'pro']);
    Telemetry::flush();

    $event = collect($this->collector->batches())->flatMap(fn ($batch) => $batch->events)->first();

    expect($event->attributes['customer.cpr'])->toBe('[CPR]')
        ->and($event->attributes['user.password'])->toBe('[REDACTED]')
        ->and($event->attributes['plan'])->toBe('pro');
});
