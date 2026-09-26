<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Instrumentation\RedisInstrumentation;
use Cbox\Telemetry\Metrics\Exemplar;
use Cbox\Telemetry\Metrics\MetricDefinition;
use Cbox\Telemetry\Metrics\MetricType;
use Cbox\Telemetry\Metrics\Stores\BufferedMetricStore;
use Cbox\Telemetry\Metrics\Stores\RedisMetricStore;
use Cbox\Telemetry\Support\SharedState;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tests\Doubles\PoolMemory;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;

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

    $this->prefix = 'telemetry_test_'.bin2hex(random_bytes(4));
    $this->store = new RedisMetricStore(app(Factory::class), 'default', $this->prefix);

    // The store's bookkeeping memo lives in shared memory, so the test
    // needs a handle on it — and a clean one per test.
    $this->pool = new PoolMemory;
    SharedState::use($this->pool);
});

afterEach(function () {
    SharedState::use(null);

    // wipe() deliberately preserves meta + indexes — tests must delete the
    // whole prefix so no keys leak into the local Redis between runs.
    if (isset($this->prefix)) {
        $connection = app(Factory::class)->connection();

        foreach (MetricType::cases() as $type) {
            $index = "{$this->prefix}:index:{$type->value}";

            foreach ($connection->smembers($index) ?: [] as $name) {
                $connection->del("{$this->prefix}:{$type->value}:{$name}");
            }

            $connection->del($index);
        }
    }
});

it('aggregates counter increments across store instances', function () {
    $definition = new MetricDefinition('orders.created', MetricType::Counter, 'Orders created');

    // Two instances simulate two PHP processes sharing the same Redis.
    $other = new RedisMetricStore(app(Factory::class), 'default', $this->prefix);

    $this->store->incrementCounter($definition, ['tenant' => 'a'], 1);
    $other->incrementCounter($definition, ['tenant' => 'a'], 2);
    $other->incrementCounter($definition, ['tenant' => 'b'], 10);

    $family = collect($this->store->collect())->firstWhere(fn ($f) => $f->name() === 'orders.created');

    $samples = collect($family->samples)->keyBy(fn ($sample) => $sample->labels['tenant']);

    expect($family->definition->description)->toBe('Orders created')
        ->and($samples['a']->value)->toBe(3.0)
        ->and($samples['b']->value)->toBe(10.0);
});

it('keeps the last gauge value per labelset', function () {
    $definition = new MetricDefinition('queue.depth', MetricType::Gauge);

    $this->store->setGauge($definition, ['queue' => 'default'], 10);
    $this->store->setGauge($definition, ['queue' => 'default'], 4);

    $family = $this->store->collect()[0];

    expect($family->samples[0]->value)->toBe(4.0);
});

it('adjusts gauges atomically across store instances', function () {
    $definition = new MetricDefinition('jobs.in_flight', MetricType::Gauge);
    $other = new RedisMetricStore(app(Factory::class), 'default', $this->prefix);

    $this->store->addGauge($definition, [], 3);
    $other->addGauge($definition, [], 2);
    $this->store->addGauge($definition, [], -1);

    expect($this->store->collect()[0]->samples[0]->value)->toBe(4.0);
});

it('round-trips histograms with buckets, sum and count', function () {
    $definition = new MetricDefinition('req.duration', MetricType::Histogram, unit: 'ms', buckets: [10.0, 100.0]);

    $this->store->recordHistogram($definition, ['route' => '/'], 5);
    $this->store->recordHistogram($definition, ['route' => '/'], 50);
    $this->store->recordHistogram($definition, ['route' => '/'], 5000);

    $sample = $this->store->collect()[0]->samples[0];

    expect($sample->labels)->toBe(['route' => '/'])
        ->and($sample->bucketCounts)->toBe([1, 1, 1])
        ->and($sample->count)->toBe(3)
        ->and($sample->sum)->toBe(5055.0)
        ->and($this->store->collect()[0]->definition->buckets)->toBe([10.0, 100.0]);
});

it('keeps the latest exemplar for a histogram series', function () {
    $definition = new MetricDefinition('req.duration', MetricType::Histogram, buckets: [10.0, 100.0]);

    $this->store->recordHistogram($definition, [], 5, new Exemplar('trace-1', 5.0, 1_000));
    $this->store->recordHistogram($definition, [], 50, new Exemplar('trace-2', 50.0, 2_000));
    $this->store->recordHistogram($definition, [], 20); // no exemplar — does not clear the last one

    $sample = $this->store->collect()[0]->samples[0];

    expect($sample->exemplar?->traceId)->toBe('trace-2')
        ->and($sample->exemplar?->value)->toBe(50.0)
        ->and($sample->exemplar?->timeUnixNano)->toBe(2000);
});

it('survives label values containing separators and unicode', function () {
    $definition = new MetricDefinition('log.lines', MetricType::Counter);
    $labels = ['source' => 'a:b|c "d" æøå', 'path' => '/x/{id}'];

    $this->store->incrementCounter($definition, $labels, 1);

    // Labels are stored canonically (sorted by key).
    expect($this->store->collect()[0]->samples[0]->labels)
        ->toBe(['path' => '/x/{id}', 'source' => 'a:b|c "d" æøå']);
});

it('wipes everything it wrote', function () {
    $this->store->incrementCounter(new MetricDefinition('a.b', MetricType::Counter), [], 1);
    $this->store->recordHistogram(new MetricDefinition('c.d', MetricType::Histogram, buckets: [1.0]), [], 2);

    $this->store->wipe();

    expect($this->store->collect())->toBeEmpty();
});

it('forgets a single series without touching its siblings', function () {
    $gauge = new MetricDefinition('queue.worker.memory.php', MetricType::Gauge, unit: 'By');
    $histogram = new MetricDefinition('req.duration', MetricType::Histogram, buckets: [10.0]);

    $this->store->setGauge($gauge, ['pid' => '1'], 100);
    $this->store->setGauge($gauge, ['pid' => '2'], 200);
    $this->store->recordHistogram($histogram, ['route' => '/a'], 5, new Exemplar('trace-1', 5.0, 1_000));
    $this->store->recordHistogram($histogram, ['route' => '/b'], 7);

    $this->store->forgetSeries($gauge, ['pid' => '1']);
    $this->store->forgetSeries($histogram, ['route' => '/a']);

    $families = collect($this->store->collect())->keyBy(fn ($f) => $f->name());

    expect($families['queue.worker.memory.php']->samples)->toHaveCount(1)
        ->and($families['queue.worker.memory.php']->samples[0]->labels['pid'])->toBe('2')
        ->and($families['req.duration']->samples)->toHaveCount(1)
        ->and($families['req.duration']->samples[0]->labels['route'])->toBe('/b');
});

it('keeps warm workers visible after a wipe from another process', function () {
    $counter = new MetricDefinition('orders.created', MetricType::Counter, 'Orders created');
    $histogram = new MetricDefinition('req.duration', MetricType::Histogram, buckets: [10.0]);

    // This instance plays the warm FPM worker: the first write sets its
    // per-process initialize() memo.
    $this->store->incrementCounter($counter, [], 1);
    $this->store->recordHistogram($histogram, [], 5);

    // Another process (telemetry:flush --wipe) resets the store.
    (new RedisMetricStore(app(Factory::class), 'default', $this->prefix))->wipe();

    expect($this->store->collect())->toBeEmpty();

    // The warm worker writes again WITHOUT re-initializing — the metrics
    // must still be collectable (meta + index membership survived).
    $this->store->incrementCounter($counter, [], 5);
    $this->store->recordHistogram($histogram, [], 7);

    $families = collect($this->store->collect())->keyBy(fn ($f) => $f->name());

    expect($families)->toHaveKeys(['orders.created', 'req.duration'])
        ->and($families['orders.created']->samples[0]->value)->toBe(5.0)
        ->and($families['orders.created']->definition->description)->toBe('Orders created')
        ->and($families['req.duration']->samples[0]->count)->toBe(1)
        ->and($families['req.duration']->samples[0]->bucketCounts)->toBe([1, 0]);
});

it('aggregates correctly through the write buffer', function () {
    $buffered = new BufferedMetricStore($this->store);

    $counter = new MetricDefinition('orders.created', MetricType::Counter);
    $histogram = new MetricDefinition('req.duration', MetricType::Histogram, buckets: [10.0, 100.0]);

    foreach (range(1, 50) as $i) {
        $buffered->incrementCounter($counter, ['tenant' => 'a'], 1);
        $buffered->recordHistogram($histogram, [], (float) $i);
    }

    // collect() flushes the buffer first, then reads Redis.
    $families = collect($buffered->collect())->keyBy(fn ($family) => $family->name());

    expect($families['orders.created']->samples[0]->value)->toBe(50.0)
        ->and($families['req.duration']->samples[0]->count)->toBe(50)
        ->and($families['req.duration']->samples[0]->bucketCounts)->toBe([10, 40, 0])
        ->and($families['req.duration']->samples[0]->sum)->toBe(1275.0);
});

it('flushes the buffered exemplar through to the inner store', function () {
    $buffered = new BufferedMetricStore($this->store);
    $histogram = new MetricDefinition('req.duration', MetricType::Histogram, buckets: [10.0, 100.0]);

    $buffered->recordHistogram($histogram, [], 5, new Exemplar('trace-1', 5.0, 1_000));
    $buffered->recordHistogram($histogram, [], 50, new Exemplar('trace-2', 50.0, 2_000));

    $sample = $buffered->collect()[0]->samples[0];

    expect($sample->exemplar?->traceId)->toBe('trace-2');
});

it('records redis command spans but never for the telemetry connections', function () {
    $collector = new CollectingExporter;
    Telemetry::addExporter($collector);

    (new RedisInstrumentation(app()))
        ->register(app('events'), ignoreConnections: ['telemetry']);

    Telemetry::span('request-ish', function () {
        Redis::connection('default')->set('telemetry:test:key', '1');
        Redis::connection('default')->del('telemetry:test:key');
    });

    Telemetry::flush();

    $spans = collect($collector->batches())->flatMap(fn ($batch) => $batch->spans)
        ->filter(fn ($span) => str_starts_with($span->name, 'redis '))->values();

    expect($spans->count())->toBeGreaterThanOrEqual(2)
        ->and($spans->firstWhere('name', 'redis SET')->attributes()['db.redis.key'])->toBe('telemetry:test:key')
        ->and($spans->firstWhere('name', 'redis SET')->isDetail())->toBeTrue();

    $families = collect(Telemetry::collect())->keyBy(fn ($f) => $f->name());

    expect(collect($families['redis.commands']->samples)->pluck('labels.command'))->toContain('SET');
})->group('redis');

/**
 * The bookkeeping (meta, __since, the index set) lives in Redis, and Redis can
 * lose it in ways this package does not control — a restart without
 * persistence, a FLUSHDB, an eviction under maxmemory. A process that had
 * memoized "initialized" kept writing data nobody could read: collect() walks
 * the index and drops any family whose __meta is gone, so the metric vanished
 * from every scrape until a COLD process happened to write it again — which,
 * for a series with one long-lived writer, is never.
 */
it('re-creates its bookkeeping after the keys are lost underneath it', function () {
    $definition = new MetricDefinition('orders.created', MetricType::Counter);

    $this->store->incrementCounter($definition, ['tenant' => 'acme'], 1.0);

    expect($this->store->collect())->not->toBeEmpty();

    // Redis loses the lot; the same warm process keeps writing.
    app(Factory::class)->connection()->flushdb();
    $this->store->incrementCounter($definition, ['tenant' => 'acme'], 1.0);

    expect($this->store->collect())->toBeEmpty('the warm process cannot re-register within the trust window');

    // Age the memo past its trust window — NOT clear it. Clearing would also
    // "pass" against a memo that is merely a boolean, which is the thing being
    // fixed: the old memo said done forever, so the window is the whole point.
    // Written straight into the shared memory, past-dated, which a caller
    // going through SharedState::remember() could not do.
    $this->pool->expireAll();

    $this->store->incrementCounter($definition, ['tenant' => 'acme'], 1.0);

    $families = $this->store->collect();

    expect($families)->not->toBeEmpty()
        ->and($families[0]->name())->toBe('orders.created');
})->group('redis');

it('writes a histogram observation as one atomic command', function () {
    // Bucket, sum and count were three round trips, and a failure
    // between them left a series whose buckets outnumber its count —
    // a number nobody can reason about afterwards.
    $definition = new MetricDefinition('http.server.request.duration', MetricType::Histogram, buckets: [0.1, 0.5, 1.0]);

    // Warm the bookkeeping so the observation is measured on its own.
    $this->store->recordHistogram($definition, ['route' => '/orders'], 0.2);

    $commands = [];
    app(Factory::class)->enableEvents();
    Event::listen(CommandExecuted::class, function (CommandExecuted $event) use (&$commands): void {
        $commands[] = $event->command;
    });

    $this->store->recordHistogram($definition, ['route' => '/orders'], 0.2);

    expect($commands)->toBe(['eval']);

    $family = collect($this->store->collect())->firstWhere(fn ($f) => $f->name() === 'http.server.request.duration');
    $sample = $family->samples[0];

    expect($sample->count)->toBe(2)
        ->and($sample->sum)->toBe(0.4)
        ->and(array_sum($sample->bucketCounts))->toBe(2);
});

it('does not re-register a metric it already registered this window', function () {
    // Under FPM the per-process memo was the request's, so every
    // request rewrote the bookkeeping of every metric it touched.
    $definition = new MetricDefinition('orders.created', MetricType::Counter);

    $this->store->incrementCounter($definition, ['tenant' => 'acme'], 1.0);

    $commands = [];
    app(Factory::class)->enableEvents();
    Event::listen(CommandExecuted::class, function (CommandExecuted $event) use (&$commands): void {
        $commands[] = $event->command;
    });

    // A different process, the same pool.
    (new RedisMetricStore(app(Factory::class), 'default', $this->prefix))
        ->incrementCounter($definition, ['tenant' => 'acme'], 1.0);

    expect($commands)->toBe(['eval']);
});

it('stops a runaway label from growing the family without bound', function () {
    // The last line of defence. Every label this package produces is
    // classified or bounded at its source, but an application declares
    // its own metrics too, and a label built from a user id is the
    // oldest mistake in the book. The budget lives in the script
    // because the series already stored live in Redis: no process can
    // count them on its own, and under FPM a per-process tally is a
    // per-request one.
    $store = new RedisMetricStore(app(Factory::class), 'default', $this->prefix, maxFields: 20);
    $definition = new MetricDefinition('orders.created', MetricType::Counter);

    for ($i = 0; $i < 200; $i++) {
        $store->incrementCounter($definition, ['user' => (string) $i], 1.0);
    }

    $fields = app(Factory::class)->connection()->hgetall("{$this->prefix}:counter:orders.created");
    $family = collect($store->collect())->firstWhere(fn ($f) => $f->name() === 'orders.created');

    expect(count($family->samples))->toBeLessThan(25)
        // And it says so, rather than leaving an operator to wonder
        // where the rest went.
        ->and((int) ($fields['__overflow'] ?? 0))->toBeGreaterThan(150);
});

it('keeps counting a series it already has after the budget is reached', function () {
    // Refusing NEW series must not stop the existing ones. The metrics
    // that were already there are the ones the dashboards are built on.
    $store = new RedisMetricStore(app(Factory::class), 'default', $this->prefix, maxFields: 5);
    $definition = new MetricDefinition('orders.created', MetricType::Counter);

    $store->incrementCounter($definition, ['tenant' => 'acme'], 1.0);

    for ($i = 0; $i < 50; $i++) {
        $store->incrementCounter($definition, ['tenant' => "t{$i}"], 1.0);
    }

    $store->incrementCounter($definition, ['tenant' => 'acme'], 1.0);

    $family = collect($store->collect())->firstWhere(fn ($f) => $f->name() === 'orders.created');
    $acme = collect($family->samples)->first(fn ($sample) => ($sample->labels['tenant'] ?? null) === 'acme');

    expect($acme->value)->toBe(2.0);
});

it('refuses a whole observation at the budget, never half of one', function () {
    // Deciding the budget per FIELD let a histogram land its sum and
    // count while its bucket was refused: buckets adding to 1 under a
    // count of 2, which is not a number anyone can read. The budget is
    // decided for the observation.
    $store = new RedisMetricStore(app(Factory::class), 'default', $this->prefix, maxFields: 5);
    $definition = new MetricDefinition('http.server.request.duration', MetricType::Histogram, buckets: [10, 100]);

    $store->recordHistogram($definition, ['route' => '/a'], 5);
    $store->recordHistogram($definition, ['route' => '/a'], 50);

    $family = collect($store->collect())->firstWhere(fn ($f) => $f->name() === 'http.server.request.duration');
    $sample = $family->samples[0];

    expect(array_sum($sample->bucketCounts))->toBe($sample->count);
});
