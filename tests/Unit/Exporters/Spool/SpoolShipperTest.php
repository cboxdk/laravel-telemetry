<?php

declare(strict_types=1);

use Cbox\Telemetry\Exporters\Spool\ArraySpool;
use Cbox\Telemetry\Exporters\Spool\SpoolShipper;
use Cbox\Telemetry\Support\ExportResult;

it('merges spooled payloads per signal into single posts', function () {
    $spool = new ArraySpool;
    $spool->push(['signal' => 'traces', 'payload' => ['resourceSpans' => [['a' => 1]]]]);
    $spool->push(['signal' => 'traces', 'payload' => ['resourceSpans' => [['a' => 2]]]]);
    $spool->push(['signal' => 'logs', 'payload' => ['resourceLogs' => [['b' => 1]]]]);

    $posts = [];
    $shipper = new SpoolShipper($spool, function (string $path, array $payload) use (&$posts) {
        $posts[] = [$path, $payload];

        return ExportResult::ok();
    });

    $result = $shipper->ship(maxBatch: 10);

    expect($result)->toBe(['shipped' => 3, 'requeued' => 0, 'dropped' => 0])
        ->and($spool->size())->toBe(0)
        ->and($posts)->toHaveCount(2)
        ->and($posts[0][0])->toBe('/v1/traces')
        ->and($posts[0][1]['resourceSpans'])->toHaveCount(2)
        ->and($posts[1][0])->toBe('/v1/logs')
        ->and($posts[1][1]['resourceLogs'])->toHaveCount(1);
});

it('chunks by max batch and keeps draining until empty', function () {
    $spool = new ArraySpool;

    foreach (range(1, 5) as $i) {
        $spool->push(['signal' => 'traces', 'payload' => ['resourceSpans' => [['i' => $i]]]]);
    }

    $posts = 0;
    $shipper = new SpoolShipper($spool, function () use (&$posts) {
        $posts++;

        return ExportResult::ok();
    });

    expect($shipper->ship(maxBatch: 2))->toBe(['shipped' => 5, 'requeued' => 0, 'dropped' => 0])
        ->and($posts)->toBe(3)
        ->and($spool->size())->toBe(0);
});

it('requeues the chunk in order when the endpoint is down', function () {
    $spool = new ArraySpool;
    $spool->push(['signal' => 'traces', 'payload' => ['resourceSpans' => [['i' => 1]]]]);
    $spool->push(['signal' => 'traces', 'payload' => ['resourceSpans' => [['i' => 2]]]]);

    $shipper = new SpoolShipper($spool, fn () => ExportResult::retryable('down'));

    expect($shipper->ship(maxBatch: 10))->toBe(['shipped' => 0, 'requeued' => 2, 'dropped' => 0])
        ->and($spool->size())->toBe(2)
        ->and($spool->pop(1)[0]['payload']['resourceSpans'][0]['i'])->toBe(1);
});

it('caps the spool with drop-oldest semantics', function () {
    $spool = new ArraySpool(maxItems: 2);

    foreach (range(1, 4) as $i) {
        $spool->push(['signal' => 'traces', 'payload' => ['resourceSpans' => [['i' => $i]]]]);
    }

    $entries = $spool->pop(10);

    expect($entries)->toHaveCount(2)
        ->and($entries[0]['payload']['resourceSpans'][0]['i'])->toBe(3);
});

it('requeues only the failed signal and never re-ships delivered ones', function () {
    $spool = new ArraySpool;
    $spool->push(['signal' => 'traces', 'payload' => ['resourceSpans' => [['t' => 1]]]]);
    $spool->push(['signal' => 'logs', 'payload' => ['resourceLogs' => [['l' => 1]]]]);

    // traces succeed, logs are down.
    $shipper = new SpoolShipper($spool, fn (string $path) => str_contains($path, 'traces')
        ? ExportResult::ok()
        : ExportResult::retryable('down'));

    $result = $shipper->ship();

    expect($result)->toBe(['shipped' => 1, 'requeued' => 1, 'dropped' => 0]);

    // Only the log entry remains — the delivered trace is gone, so a
    // second drain cannot duplicate it.
    $remaining = $spool->pop(10);
    expect($remaining)->toHaveCount(1)
        ->and($remaining[0]['signal'])->toBe('logs');
});

it('drops permanently rejected entries instead of looping forever', function () {
    $spool = new ArraySpool;
    $spool->push(['signal' => 'logs', 'payload' => ['resourceLogs' => [['poison' => 1]]]]);

    $shipper = new SpoolShipper($spool, fn () => ExportResult::failed('400 malformed'));

    expect($shipper->ship())->toBe(['shipped' => 0, 'requeued' => 0, 'dropped' => 1])
        ->and($spool->size())->toBe(0);
});
