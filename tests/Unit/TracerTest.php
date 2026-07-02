<?php

declare(strict_types=1);

use Cbox\Telemetry\Support\TraceParent;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\SpanStatus;
use Cbox\Telemetry\Tracing\Tracer;

it('nests spans under the current span', function () {
    $tracer = new Tracer;

    $parent = $tracer->startSpan('parent');
    $child = $tracer->startSpan('child');

    expect($child->traceId)->toBe($parent->traceId)
        ->and($child->parentSpanId)->toBe($parent->spanId);

    $child->end();
    $parent->end();

    expect($tracer->drain())->toHaveCount(2);
});

it('measures a closure and records exceptions', function () {
    $tracer = new Tracer;

    expect(fn () => $tracer->span('failing', function () {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class);

    $spans = $tracer->drain();

    expect($spans)->toHaveCount(1)
        ->and($spans[0]->status())->toBe(SpanStatus::Error)
        ->and($spans[0]->events()[0]->name)->toBe('exception')
        ->and($spans[0]->events()[0]->attributes['exception.message'])->toBe('boom');
});

it('returns the closure result', function () {
    $tracer = new Tracer;

    $result = $tracer->span('work', fn () => 42);

    expect($result)->toBe(42);
});

it('continues a remote trace as a child, not a detached root', function () {
    $tracer = new Tracer;

    $tracer->continueFrom(new TraceParent(
        traceId: '0af7651916cd43dd8448eb211c80319c',
        spanId: 'b7ad6b7169203331',
        sampled: true,
    ));

    $span = $tracer->startSpan('continued');

    expect($span->traceId)->toBe('0af7651916cd43dd8448eb211c80319c')
        ->and($span->parentSpanId)->toBe('b7ad6b7169203331');
});

it('respects the remote not-sampled decision', function () {
    $tracer = new Tracer;

    $tracer->continueFrom(new TraceParent(
        traceId: '0af7651916cd43dd8448eb211c80319c',
        spanId: 'b7ad6b7169203331',
        sampled: false,
    ));

    $span = $tracer->startSpan('unsampled');
    $span->end();

    expect($span->sampled)->toBeFalse()
        ->and($tracer->drain())->toBeEmpty();
});

it('never buffers when the sample rate is zero', function () {
    $tracer = new Tracer(sampleRate: 0.0);

    $tracer->span('unsampled', fn () => null);

    expect($tracer->drain())->toBeEmpty();
});

it('propagates the sampled flag through the traceparent', function () {
    $tracer = new Tracer(sampleRate: 0.0);

    $tracer->startSpan('root');

    expect($tracer->currentTraceParent()->sampled)->toBeFalse();
});

it('survives out-of-order span ends', function () {
    $tracer = new Tracer;

    $a = $tracer->startSpan('a');
    $b = $tracer->startSpan('b');

    $a->end();

    expect($tracer->currentSpan())->toBe($b);

    $b->end();

    expect($tracer->currentSpan())->toBeNull()
        ->and($tracer->drain())->toHaveCount(2);
});

it('records backdated spans with the reported duration', function () {
    $tracer = new Tracer;

    $parent = $tracer->startSpan('request');
    $span = $tracer->recordSpan('db.query', 25.0, ['db.system.name' => 'mysql'], SpanKind::Client);

    expect($span->parentSpanId)->toBe($parent->spanId)
        ->and($span->durationMs())->toEqualWithDelta(25.0, 0.001)
        ->and($span->hasEnded())->toBeTrue();
});

it('force-flushes when the buffer cap is hit', function () {
    $tracer = new Tracer(maxBuffer: 3);

    $flushes = 0;
    $tracer->onBufferFull(function () use (&$flushes, $tracer) {
        $flushes++;
        $tracer->drain();
    });

    foreach (range(1, 7) as $i) {
        $tracer->span("span {$i}", fn () => null);
    }

    expect($flushes)->toBe(2)
        ->and($tracer->bufferedCount())->toBe(1);
});

it('resets context but keeps unflushed spans', function () {
    $tracer = new Tracer;

    $tracer->span('finished', fn () => null);
    $tracer->startSpan('active');

    $tracer->resetContext();

    expect($tracer->currentSpan())->toBeNull()
        ->and($tracer->traceId())->toBeNull()
        ->and($tracer->drain())->toHaveCount(1);
});

it('ending a span twice is a no-op', function () {
    $tracer = new Tracer;

    $span = $tracer->startSpan('once');
    $span->end();
    $span->end();

    expect($tracer->drain())->toHaveCount(1);
});
