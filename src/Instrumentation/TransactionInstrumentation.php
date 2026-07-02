<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\Span;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;

/**
 * Database transaction spans — where lock time hides. One span per
 * BEGIN..COMMIT/ROLLBACK, stacked per connection so nested transactions
 * (savepoints) nest in the waterfall too. Only recorded inside a
 * sampled trace; unmatched events (transaction opened before the trace
 * started) are ignored.
 */
final class TransactionInstrumentation
{
    /** @var array<string, list<Span|null>> per-connection span stacks */
    private array $stacks = [];

    public function __construct(private readonly Container $container) {}

    public function register(Dispatcher $events): void
    {
        $events->listen(TransactionBeginning::class, fn (TransactionBeginning $event) => $this->begin($event->connectionName));
        $events->listen(TransactionCommitted::class, fn (TransactionCommitted $event) => $this->finish($event->connectionName, 'committed'));
        $events->listen(TransactionRolledBack::class, fn (TransactionRolledBack $event) => $this->finish($event->connectionName, 'rolled_back'));
    }

    private function begin(string $connection): void
    {
        FailSafe::guard(function () use ($connection) {
            $telemetry = $this->container->make(TelemetryManager::class);

            // Push null when unsampled/outside a trace, so nesting depth
            // stays aligned with the database's own transaction level.
            $span = $telemetry->currentSpan()?->sampled === true
                ? $telemetry->tracer()->startSpan('db.transaction', attributes: [
                    'db.connection' => $connection,
                    'db.transaction.depth' => count($this->stacks[$connection] ?? []),
                ])
                : null;

            $this->stacks[$connection][] = $span;
        });
    }

    private function finish(string $connection, string $outcome): void
    {
        FailSafe::guard(function () use ($connection, $outcome) {
            if (! isset($this->stacks[$connection]) || $this->stacks[$connection] === []) {
                return;
            }

            $span = array_pop($this->stacks[$connection]);

            if ($this->stacks[$connection] === []) {
                unset($this->stacks[$connection]);
            }

            $span?->setAttribute('db.transaction.outcome', $outcome);
            $span?->end();

            $telemetry = $this->container->make(TelemetryManager::class);
            $telemetry->tracer()->bumpStat('db.transaction.count', 1);

            if ($outcome === 'rolled_back') {
                $telemetry->counter('db.transactions.rolled_back', 'Database transactions rolled back')
                    ->inc(1, ['connection' => $connection]);
            }
        });
    }
}
