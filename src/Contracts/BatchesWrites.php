<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Contracts;

use Closure;

/**
 * A metric store that can send a flush's writes together.
 *
 * BufferedMetricStore aggregates a request's observations and flushes
 * them at terminate, one store call per series. Over a network that is
 * one round trip per series, which on an API request touching a few
 * dozen series is the bulk of what telemetry costs. A store that
 * implements this runs the whole flush inside batch() and sends the
 * writes in one go.
 *
 * The contract, which BufferedMetricStore relies on:
 *
 * - batch() throws only if NOTHING was applied (the store could not be
 *   reached). The caller then keeps its buffer and retries later.
 * - Once writes may have been applied, a failure is reported and the
 *   batch is dropped, never thrown: retrying a batch that half landed
 *   would count it twice, and one lost flush is cheaper than a wrong
 *   total.
 */
interface BatchesWrites
{
    /**
     * Run $writes, which calls the store's write methods, and send the
     * writes they produce together.
     *
     * @param  Closure(): void  $writes
     */
    public function batch(Closure $writes): void;
}
