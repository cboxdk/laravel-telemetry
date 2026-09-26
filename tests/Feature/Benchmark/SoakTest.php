<?php

declare(strict_types=1);

use Cbox\Telemetry\Exporters\NullExporter;
use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Http\Middleware\TraceRequest;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpFoundation\Response;

/**
 * Not a load test — a soak. It runs one process through tens of
 * thousands of units of work and asks two questions a micro-benchmark
 * cannot:
 *
 *   Does memory grow?      An Octane worker or a queue worker lives for
 *                          hours. A few bytes retained per request is a
 *                          restart loop by lunchtime, and every map in
 *                          this package is a candidate.
 *   Does it get slower?    A structure that is appended to and never
 *                          cleared degrades gradually, which is the
 *                          failure nobody catches in review and nobody
 *                          reproduces locally.
 *
 *     vendor/bin/pest --group=benchmark
 *
 * A real load test still has to happen against real infrastructure:
 * this proves the process does not rot, not that the collector or the
 * metric store keeps up.
 */
uses()->group('benchmark');

function soakRequest(TraceRequest $middleware, Connection $connection, int $i): void
{
    $request = Request::create("/orders/{$i}", 'GET');
    $response = new Response('ok');

    $middleware->handle($request, static fn (): Response => $response);

    // A realistic request does not just exist: it queries, and the
    // spans and tallies those produce are what accumulates.
    for ($q = 0; $q < 5; $q++) {
        Event::dispatch(new QueryExecuted("select * from orders where id = ? /* {$q} */", [$i], 0.4, $connection));
    }

    $middleware->terminate($request, $response);
}

/**
 * A plain object, not a Mockery double: Mockery retains every mock it
 * creates until close(), so ten thousand of them measured the test
 * framework's bookkeeping at 8.9KB a job and called it a leak.
 */
function soakJobDouble(int $i): Job
{
    return new class($i) implements Job
    {
        public function __construct(private int $i) {}

        public function resolveName(): string
        {
            return 'App\Jobs\Ship';
        }

        public function getQueue(): string
        {
            return 'default';
        }

        public function isReleased(): bool
        {
            return false;
        }

        public function payload(): array
        {
            return [];
        }

        public function attempts(): int
        {
            return 1;
        }

        public function uuid(): string
        {
            return 'uuid-'.$this->i;
        }

        public function maxTries(): ?int
        {
            return 1;
        }

        public function getJobId(): string
        {
            return (string) $this->i;
        }

        public function getRawBody(): string
        {
            return '{}';
        }

        public function fire(): void {}

        public function release($delay = 0): void {}

        public function isReleasedAfterException(): bool
        {
            return false;
        }

        public function delete(): void {}

        public function isDeleted(): bool
        {
            return false;
        }

        public function isDeletedOrReleased(): bool
        {
            return false;
        }

        public function hasFailed(): bool
        {
            return false;
        }

        public function markAsFailed(): void {}

        public function fail($e = null): void {}

        public function maxExceptions(): ?int
        {
            return null;
        }

        public function backoff(): ?int
        {
            return null;
        }

        public function retryUntil(): ?int
        {
            return null;
        }

        public function getName(): string
        {
            return 'App\Jobs\Ship';
        }

        public function getResolvedName(): string
        {
            return 'App\Jobs\Ship';
        }

        public function getConnectionName(): string
        {
            return 'redis';
        }

        public function getQueueableClass(): ?string
        {
            return null;
        }

        public function timeout(): ?int
        {
            return null;
        }

        public function resolveQueuedJobClass(): string
        {
            return 'App\Jobs\Ship';
        }
    };
}

function soakJob(int $i): void
{
    $job = soakJobDouble($i);

    Event::dispatch(new JobProcessing('redis', $job));
    Event::dispatch(new JobProcessed('redis', $job));
}

/**
 * @param  Closure(int): void  $unit
 * @return array{growthBytes: int, firstMs: float, lastMs: float}
 */
function soak(Closure $unit, int $iterations): array
{
    // Warm: the first hundred allocate the structures everything after
    // them reuses, and counting those as growth would fail every run.
    for ($i = 0; $i < 100; $i++) {
        $unit($i);
    }

    gc_collect_cycles();
    $before = memory_get_usage();

    $first = hrtime(true);
    for ($i = 100; $i < 600; $i++) {
        $unit($i);
    }
    $firstMs = (hrtime(true) - $first) / 1_000_000 / 500;

    for ($i = 600; $i < $iterations - 500; $i++) {
        $unit($i);
    }

    $last = hrtime(true);
    for ($i = $iterations - 500; $i < $iterations; $i++) {
        $unit($i);
    }
    $lastMs = (hrtime(true) - $last) / 1_000_000 / 500;

    gc_collect_cycles();

    return [
        'growthBytes' => memory_get_usage() - $before,
        'firstMs' => $firstMs,
        'lastMs' => $lastMs,
    ];
}

it('does not grow or slow down over ten thousand requests', function () {
    Telemetry::addExporter(new NullExporter);

    $middleware = app(TraceRequest::class);
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getName')->andReturn('mysql');
    $connection->shouldReceive('getDriverName')->andReturn('mysql');

    $result = soak(static fn (int $i) => soakRequest($middleware, $connection, $i), 10_000);

    fwrite(STDERR, sprintf(
        "\n[soak] %-22s growth=%+.2f MB  first=%.3fms  last=%.3fms  drift=%+.1f%%\n",
        '10k requests',
        $result['growthBytes'] / 1_048_576,
        $result['firstMs'],
        $result['lastMs'],
        $result['firstMs'] > 0 ? ($result['lastMs'] - $result['firstMs']) / $result['firstMs'] * 100 : 0,
    ));

    // A megabyte over ten thousand requests is a hundred bytes each,
    // which is noise; ten is a leak.
    expect($result['growthBytes'])->toBeLessThan(4 * 1_048_576)
        // And the last five hundred must not cost meaningfully more
        // than the first five hundred.
        ->and($result['lastMs'])->toBeLessThan($result['firstMs'] * 2 + 0.1);
});

it('does not grow or slow down over ten thousand jobs', function () {
    Telemetry::addExporter(new NullExporter);

    $result = soak(static fn (int $i) => soakJob($i), 10_000);

    fwrite(STDERR, sprintf(
        "\n[soak] %-22s growth=%+.2f MB  first=%.3fms  last=%.3fms  drift=%+.1f%%\n",
        '10k jobs',
        $result['growthBytes'] / 1_048_576,
        $result['firstMs'],
        $result['lastMs'],
        $result['firstMs'] > 0 ? ($result['lastMs'] - $result['firstMs']) / $result['firstMs'] * 100 : 0,
    ));

    expect($result['growthBytes'])->toBeLessThan(4 * 1_048_576)
        ->and($result['lastMs'])->toBeLessThan($result['firstMs'] * 2 + 0.1);
});
