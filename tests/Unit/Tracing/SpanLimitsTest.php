<?php

declare(strict_types=1);

use Cbox\Telemetry\Tracing\Tracer;

/**
 * A span is built from whatever the application hands it, and nothing
 * downstream bounds it: a loop that annotates per iteration, a job that
 * adds an event per row. Held in memory until the request ends and then
 * serialised whole, an unbounded span is a memory limit hit inside the
 * observability code.
 */
it('stops taking attributes at the OTel limit and says how many it refused', function () {
    $span = (new Tracer)->startSpan('work');

    for ($i = 0; $i < 1_000; $i++) {
        $span->setAttribute("key.{$i}", $i);
    }

    expect($span->attributes())->toHaveCount(128)
        ->and($span->droppedAttributes())->toBe(872);
});

it('keeps overwriting an attribute it already has', function () {
    // The limit is on distinct keys. A loop that updates one attribute
    // must not be throttled by it.
    $span = (new Tracer)->startSpan('work');

    for ($i = 0; $i < 1_000; $i++) {
        $span->setAttribute('progress', $i);
    }

    expect($span->attributes()['progress'])->toBe(999)
        ->and($span->droppedAttributes())->toBe(0);
});

it('stops taking events at the OTel limit', function () {
    $span = (new Tracer)->startSpan('work');

    for ($i = 0; $i < 1_000; $i++) {
        $span->addEvent("step.{$i}");
    }

    expect($span->events())->toHaveCount(128)
        ->and($span->droppedEvents())->toBe(872);
});

it('still records the exception on a span whose events are full', function () {
    // The most valuable event on the span, and the one a cache
    // instrumentation would otherwise have crowded out.
    $span = (new Tracer)->startSpan('work');

    for ($i = 0; $i < 1_000; $i++) {
        $span->addEvent("cache.get.{$i}");
    }

    $span->recordException(new RuntimeException('the payment gateway refused'));

    $names = array_map(static fn ($event) => $event->name, $span->events());

    expect($names)->toContain('exception')
        ->and($span->attributes()['error.type'] ?? null)->toBe(RuntimeException::class);
});

it('truncates an attribute value rather than carrying it whole', function () {
    $span = (new Tracer)->startSpan('work');
    $span->setAttribute('db.query.text', str_repeat('a', 100_000));

    $value = $span->attributes()['db.query.text'];

    expect($value)->toBeString()
        ->and(strlen((string) $value))->toBeLessThan(9_000)
        ->and($value)->toEndWith('(truncated)');
});

it('drops spans rather than growing when the buffer cannot be drained', function () {
    // No flush callback: a tracer used directly, or a flush that was
    // declined because the circuit is open. Buffering anyway is how an
    // observability library gets a worker OOM-killed.
    $tracer = new Tracer(maxBuffer: 50);

    for ($i = 0; $i < 500; $i++) {
        $tracer->startSpan("work.{$i}")->end();
    }

    expect($tracer->bufferedCount())->toBe(50)
        ->and($tracer->droppedSpans())->toBe(450);
});
