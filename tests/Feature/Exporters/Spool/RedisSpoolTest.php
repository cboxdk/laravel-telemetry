<?php

declare(strict_types=1);

use Cbox\Telemetry\Exporters\Spool\RedisSpool;
use Illuminate\Contracts\Redis\Factory;

uses()->group('redis');

beforeEach(function () {
    if (! extension_loaded('redis')) {
        $this->markTestSkipped('ext-redis is not installed.');
    }

    try {
        app(Factory::class)->connection()->ping();
    } catch (Throwable) {
        $this->markTestSkipped('No Redis server available on the default connection.');
    }

    $this->key = 'telemetry_test_spool_'.bin2hex(random_bytes(4));
    $this->spool = new RedisSpool(app(Factory::class), 'default', $this->key, maxItems: 3);
});

afterEach(function () {
    if (isset($this->key)) {
        app(Factory::class)->connection()->del($this->key);
    }
});

function spoolEntry(string $id): array
{
    return ['signal' => 'traces', 'payload' => ['id' => $id]];
}

it('round-trips entries in FIFO order', function () {
    $this->spool->push(spoolEntry('a'));
    $this->spool->push(spoolEntry('b'));

    expect($this->spool->size())->toBe(2);

    $entries = $this->spool->pop(10);

    expect($entries)->toHaveCount(2)
        ->and($entries[0]['payload']['id'])->toBe('a')
        ->and($entries[1]['payload']['id'])->toBe('b')
        ->and($this->spool->size())->toBe(0);
});

it('drops the OLDEST entries at the cap, never the newest', function () {
    foreach (['a', 'b', 'c', 'd', 'e'] as $id) {
        $this->spool->push(spoolEntry($id));
    }

    expect($this->spool->size())->toBe(3);

    $ids = array_map(fn (array $entry) => $entry['payload']['id'], $this->spool->pop(10));

    // maxItems = 3: 'a' and 'b' were sacrificed, the newest survive.
    expect($ids)->toBe(['c', 'd', 'e']);
});

it('requeues entries at the front in their original order', function () {
    foreach (['a', 'b', 'c'] as $id) {
        $this->spool->push(spoolEntry($id));
    }

    $batch = $this->spool->pop(2); // a, b

    // The shipper failed — the batch goes back in front of 'c'.
    $this->spool->requeue($batch);

    $ids = array_map(fn (array $entry) => $entry['payload']['id'], $this->spool->pop(10));

    expect($ids)->toBe(['a', 'b', 'c']);
});

it('silently discards malformed entries on pop', function () {
    $connection = app(Factory::class)->connection();

    // A roomy cap — this test must not trip drop-oldest trimming.
    $spool = new RedisSpool(app(Factory::class), 'default', $this->key, maxItems: 100);

    $spool->push(spoolEntry('valid-1'));
    $connection->rpush($this->key, 'not json at all');
    $connection->rpush($this->key, json_encode(['signal' => 'traces'])); // missing payload
    $connection->rpush($this->key, json_encode(['payload' => []])); // missing signal
    $spool->push(spoolEntry('valid-2'));

    $entries = $spool->pop(10);

    $ids = array_map(fn (array $entry) => $entry['payload']['id'] ?? null, $entries);

    expect($ids)->toBe(['valid-1', 'valid-2'])
        ->and($spool->size())->toBe(0);
});

it('stops popping at an empty list', function () {
    $this->spool->push(spoolEntry('only'));

    expect($this->spool->pop(100))->toHaveCount(1)
        ->and($this->spool->pop(100))->toBe([]);
});
