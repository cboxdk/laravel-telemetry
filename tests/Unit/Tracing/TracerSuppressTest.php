<?php

declare(strict_types=1);

use Cbox\Telemetry\Support\TraceParent;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\Tracer;

it('exports nothing from a suppressed unit — not even error spans', function () {
    $tracer = new Tracer(sampleRate: 1.0, alwaysSampleErrors: true);
    $tracer->suppress();

    $tracer->span('healthy', fn () => null);
    $tracer->recordSpan('db.query', 3.0, kind: SpanKind::Client);

    try {
        $tracer->span('failing', fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }

    $detached = $tracer->startDetachedSpan('GET tempo.test', SpanKind::Client);
    $detached->end();

    expect($tracer->drain())->toBe([])
        ->and($tracer->currentlySampled())->toBeFalse()
        ->and($tracer->currentlySampled($detached))->toBeFalse();
});

it('parents spans inside a suppressed unit to each other rather than opening new roots', function () {
    $tracer = new Tracer;
    $tracer->suppress();

    $outer = $tracer->startSpan('outer');
    $inner = $tracer->startSpan('inner');

    expect($inner->parentSpanId)->toBe($outer->spanId)
        ->and($inner->traceId)->toBe($outer->traceId)
        ->and($inner->sampled)->toBeFalse();
});

it('exposes and propagates no trace context while suppressed', function () {
    $tracer = new Tracer;
    $tracer->continueFrom(TraceParent::parse('00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01') ?? throw new LogicException);
    $tracer->suppress();
    $tracer->startSpan('inside');

    expect($tracer->traceId())->toBeNull()
        ->and($tracer->currentTraceParent())->toBeNull();
});

it('is lifted by resetContext', function () {
    $tracer = new Tracer;
    $tracer->suppress();
    $tracer->span('dropped', fn () => null);
    $tracer->resetContext();

    $tracer->span('kept', fn () => null);

    expect($tracer->suppressed())->toBeFalse()
        ->and(array_map(fn ($span) => $span->name, $tracer->drain()))->toBe(['kept']);
});
