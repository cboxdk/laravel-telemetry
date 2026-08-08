<?php

declare(strict_types=1);

use Cbox\Telemetry\Exporters\Spool\SqliteSpool;

beforeEach(function () {
    if (! extension_loaded('pdo_sqlite')) {
        $this->markTestSkipped('The sqlite spool needs ext-pdo_sqlite.');
    }

    $this->path = sys_get_temp_dir().'/telemetry_test_'.bin2hex(random_bytes(6)).'/spool.sqlite';
    $this->spool = new SqliteSpool($this->path, maxItems: 3);
    $this->entry = fn (string $id): array => ['signal' => 'traces', 'payload' => ['id' => $id]];
    $this->ids = fn (array $entries): array => array_map(fn (array $e) => $e['payload']['id'] ?? null, $entries);
});

afterEach(function () {
    if (! isset($this->path)) {
        return;
    }

    foreach (glob($this->path.'*') ?: [] as $file) {
        @unlink($file);
    }

    if (is_dir(dirname($this->path))) {
        @rmdir(dirname($this->path));
    }
});

it('round-trips entries in FIFO order', function () {
    $this->spool->push(($this->entry)('a'));
    $this->spool->push(($this->entry)('b'));

    expect($this->spool->size())->toBe(2);

    $entries = $this->spool->pop(10);

    expect(($this->ids)($entries))->toBe(['a', 'b'])
        ->and($this->spool->size())->toBe(0);
});

it('drops the OLDEST entries at the cap, never the newest', function () {
    foreach (['a', 'b', 'c', 'd', 'e'] as $id) {
        $this->spool->push(($this->entry)($id));
    }

    expect($this->spool->size())->toBe(3)
        ->and(($this->ids)($this->spool->pop(10)))->toBe(['c', 'd', 'e']);
});

it('requeues entries at the front in their original order', function () {
    foreach (['a', 'b', 'c'] as $id) {
        $this->spool->push(($this->entry)($id));
    }

    $batch = $this->spool->pop(2); // a, b

    // The shipper failed — the batch goes back in front of 'c'.
    $this->spool->requeue($batch);

    expect(($this->ids)($this->spool->pop(10)))->toBe(['a', 'b', 'c']);
});

it('keeps requeued entries at the front when new ones arrive after', function () {
    $spool = new SqliteSpool($this->path, maxItems: 100);

    $spool->push(($this->entry)('a'));
    $spool->push(($this->entry)('b'));

    $spool->requeue($spool->pop(1)); // 'a' goes back to the front
    $spool->push(($this->entry)('c'));

    expect(($this->ids)($spool->pop(10)))->toBe(['a', 'b', 'c']);
});

it('survives the process that spooled it', function () {
    $this->spool->push(($this->entry)('a'));
    $this->spool->push(($this->entry)('b'));

    // The phone was killed in a tunnel; the next launch still owes these.
    $reopened = new SqliteSpool($this->path, maxItems: 3);

    expect($reopened->size())->toBe(2)
        ->and(($this->ids)($reopened->pop(10)))->toBe(['a', 'b']);
});

it('silently discards malformed entries on pop', function () {
    $spool = new SqliteSpool($this->path, maxItems: 100);

    $spool->push(($this->entry)('valid-1'));
    $spool->push(($this->entry)('valid-2'));

    // Corrupt the middle of the queue the way a truncated write would.
    $pdo = new PDO('sqlite:'.$this->path);
    $pdo->exec("INSERT INTO telemetry_spool (seq, entry) VALUES (100, 'not json at all')");
    $pdo->exec("INSERT INTO telemetry_spool (seq, entry) VALUES (101, '".json_encode(['signal' => 'traces'])."')");
    $pdo->exec("INSERT INTO telemetry_spool (seq, entry) VALUES (102, '".json_encode(['payload' => []])."')");

    expect(($this->ids)($spool->pop(10)))->toBe(['valid-1', 'valid-2'])
        ->and($spool->size())->toBe(0);
});

it('stops popping at an empty queue', function () {
    $this->spool->push(($this->entry)('only'));

    expect($this->spool->pop(100))->toHaveCount(1)
        ->and($this->spool->pop(100))->toBe([]);
});
