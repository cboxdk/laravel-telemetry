<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Testing;

/**
 * Every recorded query of one SQL shape — the unit a performance failure
 * names, because "23 queries" is a number and "20× select … from
 * order_items where order_id = ?, at OrderController.php:42" is a fix.
 *
 * `sql` is the shape's text (SqlShape::normalize): values out, `in (…)`
 * lists collapsed. `origin` is the first `code.*` location any of its
 * spans or detection events carried — null when the query instrumentation
 * captured none (see `telemetry.instrument.query_origin`).
 */
final readonly class QueryGroup
{
    public function __construct(
        public string $shape,
        public string $sql,
        public int $count,
        public float $durationMs,
        public ?string $origin = null,
        public bool $nPlusOne = false,
        public bool $duplicate = false,
    ) {}

    /**
     * One line per group, plus the origin on the next one:
     *
     *   20× select * from "order_items" where "order_id" = ?  (4.1 ms)
     *       at app/Http/Controllers/OrderController.php:42 (OrderController::index)
     */
    public function describe(): string
    {
        $sql = mb_strlen($this->sql) > 240 ? mb_substr($this->sql, 0, 239).'…' : $this->sql;

        $line = sprintf('  %3d× %s  (%s ms)', $this->count, $sql, round($this->durationMs, 2));

        return $this->origin === null ? $line : $line."\n        at {$this->origin}";
    }
}
