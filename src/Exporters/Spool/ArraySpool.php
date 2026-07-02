<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Exporters\Spool;

/**
 * In-memory spool for tests.
 */
final class ArraySpool implements Spool
{
    /** @var list<array{signal: string, payload: array<string, mixed>}> */
    private array $entries = [];

    public function __construct(private readonly int $maxItems = 20000) {}

    public function push(array $entry): void
    {
        $this->entries[] = $entry;
        $this->entries = array_slice($this->entries, -$this->maxItems);
    }

    public function pop(int $count): array
    {
        $popped = array_slice($this->entries, 0, $count);
        $this->entries = array_slice($this->entries, count($popped));

        return $popped;
    }

    public function requeue(array $entries): void
    {
        $this->entries = [...$entries, ...$this->entries];
    }

    public function size(): int
    {
        return count($this->entries);
    }
}
