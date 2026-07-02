<?php

declare(strict_types=1);

use Cbox\Telemetry\Support\Ids;
use Cbox\Telemetry\Support\TraceParent;

it('parses a valid traceparent header', function () {
    $parent = TraceParent::parse('00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01');

    expect($parent)->not->toBeNull()
        ->and($parent->traceId)->toBe('0af7651916cd43dd8448eb211c80319c')
        ->and($parent->spanId)->toBe('b7ad6b7169203331')
        ->and($parent->sampled)->toBeTrue();
});

it('parses the not-sampled flag', function () {
    $parent = TraceParent::parse('00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-00');

    expect($parent->sampled)->toBeFalse();
});

it('round-trips through toString', function () {
    $header = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';

    expect(TraceParent::parse($header)->toString())->toBe($header);
});

it('rejects malformed headers', function (?string $header) {
    expect(TraceParent::parse($header))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'garbage' => ['not-a-traceparent'],
    'all-zero trace id' => ['00-00000000000000000000000000000000-b7ad6b7169203331-01'],
    'all-zero span id' => ['00-0af7651916cd43dd8448eb211c80319c-0000000000000000-01'],
    'short trace id' => ['00-0af7651916cd43dd-b7ad6b7169203331-01'],
    'version ff' => ['ff-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'],
    'uppercase hex' => ['00-0AF7651916CD43DD8448EB211C80319C-b7ad6b7169203331-01'],
]);

it('generates valid ids', function () {
    expect(Ids::isValidTraceId(Ids::traceId()))->toBeTrue()
        ->and(Ids::isValidSpanId(Ids::spanId()))->toBeTrue()
        ->and(Ids::traceId())->not->toBe(Ids::traceId());
});
