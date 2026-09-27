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

it('keeps a long value whole rather than cutting it before redaction', function () {
    // A span-level length cap looks harmless and is not: redaction runs
    // at export, and cutting `…https://alice:hunter2@example.test/` at
    // the `@` leaves a string the userinfo pattern no longer matches,
    // so the password ships. Length is the redactor's to cap, after it
    // has redacted.
    $span = (new Tracer)->startSpan('work');
    $span->setAttribute('db.query.text', str_repeat('a', 100_000));

    expect(strlen((string) $span->attributes()['db.query.text']))->toBe(100_000);
});

it('drops whole spans once the buffer is heavy, not just numerous', function () {
    // Five thousand spans is a sensible ceiling for ordinary spans and
    // no ceiling at all for spans carrying a 100KB query each.
    $tracer = new Tracer(maxBuffer: 10_000, maxBufferBytes: 2 * 1024 * 1024);

    for ($i = 0; $i < 200; $i++) {
        $tracer->startSpan("work.{$i}")
            ->setAttribute('db.query.text', str_repeat('a', 100_000))
            ->end();
    }

    expect($tracer->bufferedCount())->toBeLessThan(25)
        ->and($tracer->droppedSpans())->toBeGreaterThan(175);
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

it('applies the limit to attributes handed in at construction', function () {
    // The way most spans are actually built. Assigning the array
    // straight through let a thousand attributes past the gate and
    // reported nothing dropped.
    $attributes = [];

    for ($i = 0; $i < 1_000; $i++) {
        $attributes["key.{$i}"] = $i;
    }

    $span = (new Tracer)->startSpan('work', attributes: $attributes);

    expect($span->attributes())->toHaveCount(128)
        ->and($span->droppedAttributes())->toBe(872);
});

it('bounds the attributes on an event too', function () {
    $attributes = [];

    for ($i = 0; $i < 1_000; $i++) {
        $attributes["key.{$i}"] = $i;
    }

    $span = (new Tracer)->startSpan('work');
    $span->addEvent('cache.get', $attributes);

    expect($span->events()[0]->attributes)->toHaveCount(128)
        // And says how many it refused: an event that lost half of
        // what it was told must not look like one that was told half
        // as much.
        ->and($span->events()[0]->droppedAttributes)->toBe(872);
});

it('bounds an open span, not only the finished buffer', function () {
    // The tracer's byte budget covers finished spans, which is no help
    // while one is still open: sixty-four 1MiB attributes were held
    // until it ended, whatever the buffer's ceiling said.
    $span = (new Tracer)->startSpan('work');

    for ($i = 0; $i < 64; $i++) {
        $span->setAttribute("chunk.{$i}", str_repeat('a', 1024 * 1024));
    }

    expect($span->approximateBytes())->toBeLessThan(3 * 1024 * 1024)
        ->and($span->droppedAttributes())->toBeGreaterThan(50);
});

it('does not let a rewritten attribute climb the ceiling', function () {
    // A loop that updates one attribute must not exhaust the budget by
    // counting every version of it.
    $span = (new Tracer)->startSpan('work');

    for ($i = 0; $i < 200; $i++) {
        $span->setAttribute('progress', str_repeat('a', 100_000));
    }

    $span->setAttribute('outcome', 'ok');

    expect($span->attributes())->toHaveKey('outcome')
        ->and($span->droppedAttributes())->toBe(0);
});

it('still records the exception on a span that has hit its byte ceiling', function () {
    $span = (new Tracer)->startSpan('work');

    for ($i = 0; $i < 64; $i++) {
        $span->setAttribute("chunk.{$i}", str_repeat('a', 1024 * 1024));
    }

    $span->recordException(new RuntimeException('the payment gateway refused'));

    expect($span->attributes()['error.type'] ?? null)->toBe(RuntimeException::class);
});
