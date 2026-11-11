<?php

declare(strict_types=1);

use Cbox\Telemetry\Exporters\Spool\ArraySpool;
use Cbox\Telemetry\Exporters\Spool\SpoolShipper;
use Cbox\Telemetry\Support\ExportResult;
use Cbox\Telemetry\Support\ExportStatus;

it('keeps metric requests separate and reports permanent rejection without resending accepted logs', function () {
    $spool = new ArraySpool;
    $spool->push(['signal' => 'logs', 'payload' => ['resourceLogs' => [['log' => 1]]]]);
    $spool->push(['signal' => 'metrics', 'payload' => ['resourceMetrics' => [['sample' => 1]]]]);
    $spool->push(['signal' => 'metrics', 'payload' => ['resourceMetrics' => [['sample' => 2]]]]);
    $posts = [];
    $shipper = new SpoolShipper($spool, function (string $path, array $payload) use (&$posts) {
        $posts[] = [$path, $payload];

        return $path === '/v1/metrics' ? ExportResult::failed('invalid metric') : ExportResult::ok();
    });
    $result = $shipper->ship();
    expect($result->shipped)->toBe(1)
        ->and($result->dropped)->toBe(2)
        ->and($result->requeued)->toBe(0)
        ->and($result->successful())->toBeFalse()
        ->and($spool->size())->toBe(0)
        ->and($posts)->toBe([
            ['/v1/logs', ['resourceLogs' => [['log' => 1]]]],
            ['/v1/metrics', ['resourceMetrics' => [['sample' => 1]]]],
            ['/v1/metrics', ['resourceMetrics' => [['sample' => 2]]]],
        ]);
});

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

    expect($result->shipped)->toBe(3)
        ->and($result->requeued)->toBe(0)
        ->and($result->dropped)->toBe(0)
        ->and($result->successful())->toBeTrue()
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

    expect($shipper->ship(maxBatch: 2)->shipped)->toBe(5)
        ->and($posts)->toBe(3)
        ->and($spool->size())->toBe(0);
});

it('requeues the chunk in order when the endpoint is down', function () {
    $spool = new ArraySpool;
    $spool->push(['signal' => 'traces', 'payload' => ['resourceSpans' => [['i' => 1]]]]);
    $spool->push(['signal' => 'traces', 'payload' => ['resourceSpans' => [['i' => 2]]]]);

    $shipper = new SpoolShipper($spool, fn () => ExportResult::retryable('down'));

    $result = $shipper->ship(maxBatch: 10);

    expect($result->shipped)->toBe(0)
        ->and($result->requeued)->toBe(2)
        ->and($result->dropped)->toBe(0)
        ->and($result->successful())->toBeFalse()
        ->and($result->failures[0]->status)->toBe(ExportStatus::Retryable)
        ->and($result->failures[0]->reason)->toBe('down')
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

    expect($result->shipped)->toBe(1)
        ->and($result->requeued)->toBe(1)
        ->and($result->dropped)->toBe(0);

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

    $result = $shipper->ship();

    expect($result->dropped)->toBe(1)
        ->and($spool->size())->toBe(0)
        // Dropped means gone. The drain has to be able to say why, or
        // "dropped: 1" is indistinguishable from "delivered: 1".
        ->and($result->successful())->toBeFalse()
        ->and($result->failures)->toHaveCount(1)
        ->and($result->failures[0]->exporter)->toBe('otlp logs')
        ->and($result->failures[0]->status)->toBe(ExportStatus::Failed)
        ->and($result->failures[0]->reason)->toBe('400 malformed');
});

it('splits merged traces into byte-bounded requests', function () {
    $spool = new ArraySpool;
    // Two entries that together exceed the merge budget must not share a POST.
    $spool->push(['signal' => 'traces', 'payload' => ['resourceSpans' => [['data' => str_repeat('a', 5 * 1024 * 1024)]]]]);
    $spool->push(['signal' => 'traces', 'payload' => ['resourceSpans' => [['data' => str_repeat('b', 5 * 1024 * 1024)]]]]);

    $posts = [];
    $shipper = new SpoolShipper($spool, function (string $path, array $payload) use (&$posts) {
        $posts[] = $payload;

        return ExportResult::ok();
    });

    $result = $shipper->ship(maxBatch: 10);

    expect($result->shipped)->toBe(2)
        ->and($result->dropped)->toBe(0)
        ->and($posts)->toHaveCount(2)
        ->and(strlen((string) json_encode($posts[0])))->toBeLessThan(8 * 1024 * 1024)
        ->and(strlen((string) json_encode($posts[1])))->toBeLessThan(8 * 1024 * 1024);
});

it('rejects an oversized entry alone, not with its healthy neighbours', function () {
    $spool = new ArraySpool;
    $spool->push(['signal' => 'traces', 'payload' => ['resourceSpans' => [['ok' => 1]]]]);
    $spool->push(['signal' => 'traces', 'payload' => ['resourceSpans' => [['data' => str_repeat('a', 9 * 1024 * 1024)]]]]);
    $spool->push(['signal' => 'traces', 'payload' => ['resourceSpans' => [['ok' => 2]]]]);

    $shipper = new SpoolShipper($spool, fn (string $path, array $payload) => strlen((string) json_encode($payload)) > 8 * 1024 * 1024
        ? ExportResult::failed('413 payload too large')
        : ExportResult::ok());

    $result = $shipper->ship(maxBatch: 10);

    expect($result->shipped)->toBe(2)
        ->and($result->dropped)->toBe(1)
        ->and($spool->size())->toBe(0);
});
