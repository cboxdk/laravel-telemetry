<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Instrumentation\CommandInstrumentation;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\SpanStatus;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\TransferStats;
use Illuminate\Auth\GenericUser;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class GapDispatchJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void {}
}

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);
});

function allSpans(CollectingExporter $collector)
{
    Telemetry::flush();

    return collect($collector->batches())->flatMap(fn ($batch) => $batch->spans);
}

it('auto-instruments outgoing http client requests', function () {
    Http::fake(['api.stripe.test/*' => Http::response(['ok' => true], 200)]);

    Telemetry::span('work', fn () => Http::get('https://api.stripe.test/v1/charges'));

    $span = allSpans($this->collector)->firstWhere('name', 'GET api.stripe.test');

    expect($span)->not->toBeNull()
        ->and($span->kind)->toBe(SpanKind::Client)
        ->and($span->attributes()['server.address'])->toBe('api.stripe.test')
        ->and($span->attributes()['url.path'])->toBe('/v1/charges')
        ->and($span->attributes()['http.response.status_code'])->toBe(200);

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());

    expect($families)->toHaveKey('http.client.request.duration')
        ->and($families['http.client.request.duration']->samples[0]->labels['server.address'])->toBe('api.stripe.test');
});

it('bounds outgoing-host metric labels through classifyHttpHostsUsing', function () {
    // server.address is a METRIC label, so an app that calls a host the user
    // supplied — an OAuth issuer pasted into a form, a customer webhook —
    // grows a permanent series per hostname.
    Telemetry::classifyHttpHostsUsing(fn (string $host) => str_ends_with($host, '.stripe.test') ? 'stripe' : 'other');

    Http::fake(['*' => Http::response('ok', 200)]);

    Http::get('https://api.stripe.test/v1/charges');
    Http::get('https://whatever-a-customer-typed.example/.well-known/openid-configuration');

    $labels = collect(Telemetry::collect())
        ->firstWhere(fn ($family) => $family->name() === 'http.client.request.duration')
        ->samples;

    expect(collect($labels)->map(fn ($sample) => $sample->labels['server.address'])->unique()->sort()->values()->all())
        ->toBe(['other', 'stripe']);

    // The span keeps the real hostname — per-occurrence it costs nothing, and
    // it is what you need when reading the trace.
    expect(allSpans($this->collector)->pluck('name'))
        ->toContain('GET whatever-a-customer-typed.example');
});

it('drops outgoing-host metrics the classifier rejects, keeping the span', function () {
    Telemetry::classifyHttpHostsUsing(fn (string $host) => str_ends_with($host, '.stripe.test') ? 'stripe' : null);

    Http::fake(['*' => Http::response('ok', 200)]);

    Http::get('https://whatever-a-customer-typed.example/callback');

    $family = collect(Telemetry::collect())
        ->firstWhere(fn ($f) => $f->name() === 'http.client.request.duration');

    expect($family?->samples ?? [])->toBeEmpty()
        ->and(allSpans($this->collector)->pluck('name'))
        ->toContain('GET whatever-a-customer-typed.example');
});

it('marks 4xx outgoing responses as errors and never captures the query string', function () {
    Http::fake(['api.stripe.test/*' => Http::response('nope', 403)]);

    Http::get('https://api.stripe.test/v1/charges?api_key=SECRET');

    $span = allSpans($this->collector)->firstWhere('name', 'GET api.stripe.test');

    expect($span->status())->toBe(SpanStatus::Error)
        ->and(json_encode($span->attributes()))->not->toContain('SECRET');
});

it('counts dispatched jobs and measures queue wait time on the worker side', function () {
    config()->set('queue.default', 'sync');

    // Dispatch counts even on sync (payload factory runs).
    Bus::dispatch(new GapDispatchJob);

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());

    expect($families)->toHaveKey('queue.jobs.dispatched');

    // Worker-side wait time from a payload carrying dispatched_at:
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\Jobs\Waited');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('payload')->andReturn([
        'telemetry' => ['dispatched_at' => microtime(true) - 1.5],
    ]);

    app('queue');
    app('events')->dispatch(new JobProcessing('redis', $job));
    app('events')->dispatch(new JobProcessed('redis', $job));

    $consumer = allSpans($this->collector)->firstWhere(fn ($span) => str_contains($span->name, 'Waited'));

    expect($consumer->attributes()['messaging.wait_time_ms'])->toBeGreaterThan(1000);

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());

    expect($families)->toHaveKey('queue.job.wait_time');
});

it('counts reported exceptions — including handled ones', function () {
    report(new RuntimeException('handled and swallowed'));

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());

    expect($families)->toHaveKey('exceptions.reported')
        ->and($families['exceptions.reported']->samples[0]->labels['exception'])->toBe(RuntimeException::class);
});

it('annotates the active span when a handled exception is reported', function () {
    Telemetry::span('resilient.work', function () {
        report(new RuntimeException('logged, not thrown'));
    });

    $span = allSpans($this->collector)->firstWhere('name', 'resilient.work');

    expect($span->status())->toBe(SpanStatus::Ok)
        ->and($span->events()[0]->name)->toBe('exception')
        ->and($span->events()[0]->attributes['exception.message'])->toBe('logged, not thrown');
});

it('attaches query tallies to the root span', function () {
    Route::get('/tally', function () {
        DB::select('select 1');
        DB::select('select 2');

        return 'ok';
    });

    $this->get('/tally')->assertOk();

    $root = allSpans($this->collector)->firstWhere('name', 'GET /tally');

    expect($root->attributes()['db.query.count'])->toBe(2)
        ->and($root->attributes())->toHaveKey('db.query.time_ms');
});

it('records command metrics alongside command spans', function () {
    $instrumentation = new CommandInstrumentation(app());
    $instrumentation->register(app('events'));

    app('events')->dispatch(new CommandStarting('demo:thing', new ArrayInput([]), new NullOutput));
    app('events')->dispatch(new CommandFinished('demo:thing', new ArrayInput([]), new NullOutput, 0));

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());

    expect($families)->toHaveKeys(['command.duration', 'commands.completed'])
        ->and($families['commands.completed']->samples[0]->labels['command'])->toBe('demo:thing');
});

it('enriches user attribution through the opt-in resolver', function () {
    Route::get('/me', fn () => 'ok');

    Telemetry::resolveUserUsing(fn ($user) => ['user.name' => $user->name ?? 'unknown']);

    $this->actingAs(new GenericUser(['id' => 9, 'name' => 'Jared']));

    $this->get('/me');

    $span = allSpans($this->collector)->firstWhere('name', 'GET /me');

    expect($span->attributes()['user.id'])->toBe('9')
        ->and($span->attributes()['user.name'])->toBe('Jared');
});

it('self-reports worker memory after each job for leak tracking', function () {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\Jobs\LeakyJob');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('payload')->andReturn([]);

    app('queue');
    app('events')->dispatch(new JobProcessing('redis', $job));
    app('events')->dispatch(new JobProcessed('redis', $job));

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());

    expect($families)->toHaveKey('queue.worker.memory.php');

    $sample = $families['queue.worker.memory.php']->samples[0];

    // A distribution by queue, not a gauge per pid: the pid was unbounded and
    // its series were retired only on a graceful stop, which a worker killed by
    // the OOM killer never reaches.
    expect($sample->sum)->toBeGreaterThan(1_000_000)
        ->and($sample->count)->toBe(1)
        ->and($sample->labels)->toBe(['queue' => 'default']);
});

it('samples host and process metrics via telemetry:monitor --once', function () {
    config()->set('telemetry.monitor.processes', ['php-tests' => 'php']);

    $this->artisan('telemetry:monitor', ['--once' => true])->assertSuccessful();

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());

    expect($families)->toHaveKeys(['system.memory.usage', 'system.cpu.load_average', 'process.count'])
        ->and($families['process.count']->samples[0]->labels['process'])->toBe('php-tests')
        ->and($families['process.count']->samples[0]->value)->toBeGreaterThanOrEqual(1.0);

    // Disk + network land when the platform source supports them.
    if (isset($families['system.filesystem.usage'])) {
        expect($families['system.filesystem.usage']->samples)->not->toBeEmpty();
    }
});

/**
 * Laravel builds a FRESH Request wrapper for the connection-failure path
 * (`new Request($e->getRequest())` in PendingRequest::marshalTransportException),
 * so identity misses. The span was never ended and never exported — and because
 * it stayed on the tracer stack, every later span in the request was parented
 * under the HTTP call that had already failed. A failing dependency quietly
 * rewrote the shape of the whole trace.
 */

/**
 * Http::pool() sends several requests concurrently, and they may be identical.
 * Matching a failure to a span by method/host/path would close whichever
 * lookalike happened to be open — swapping two calls' statuses and durations.
 * The PSR request is the identity the framework actually preserves.
 */

/**
 * A Guzzle handler that answers from a script, and optionally reports cURL
 * transfer stats the way a real one would. MockHandler cannot: it produces a
 * TransferStats with no handler stats behind it.
 *
 * @param  list<PsrResponse|Throwable>  $script
 * @param  array<string, mixed>  $handlerStats
 */
function scriptedHandler(array $script, array $handlerStats = []): Closure
{
    return function ($request, array $options) use (&$script, $handlerStats) {
        $next = array_shift($script);

        if (isset($options['on_stats'])) {
            $options['on_stats'](new TransferStats($request, null, 0.1, null, $handlerStats));
        }

        return $next instanceof Throwable
            ? Create::rejectionFor($next)
            : Create::promiseFor($next);
    };
}

it('gives a redirect one span per hop, every one of them closed', function () {
    // The defect this replaces: RequestSending fires per hop while
    // ResponseReceived fires once per call, so a listener pairing them left
    // every hop but the last open forever — never exported, still the ambient
    // span, and the parent of everything that ran afterwards.
    $http = Http::setHandler(scriptedHandler([
        new PsrResponse(302, ['Location' => 'https://example.test/final']),
        new PsrResponse(200, [], 'ok'),
    ]));

    $http->get('https://example.test/start');

    // Nothing left ambient, so later work is not adopted by an open call.
    expect(Telemetry::currentSpan())->toBeNull();

    Telemetry::span('later work', fn () => null);

    $spans = allSpans($this->collector);
    $client = $spans->where('kind', SpanKind::Client)->values();

    expect($client)->toHaveCount(2)
        ->and($client[0]->attributes()['url.path'])->toBe('/start')
        ->and($client[1]->attributes()['url.path'])->toBe('/final')
        ->and($spans->firstWhere('name', 'later work')->parentSpanId)->toBeNull();
});

it('keeps pooled calls as siblings rather than nesting each under the last', function () {
    // Ambient client spans made every pooled request a CHILD of the one
    // dispatched before it. The span is detached now: its parent is fixed when
    // the hop starts and nothing else is re-parented by it.
    Telemetry::span('fan out', function () {
        Http::pool(fn ($pool) => [
            $pool->setHandler(scriptedHandler([new PsrResponse(200)]))->get('https://a.example/x'),
            $pool->setHandler(scriptedHandler([new PsrResponse(200)]))->get('https://b.example/y'),
            $pool->setHandler(scriptedHandler([new PsrResponse(200)]))->get('https://c.example/z'),
        ]);
    });

    $spans = allSpans($this->collector);
    $parent = $spans->firstWhere('name', 'fan out');
    $client = $spans->where('kind', SpanKind::Client);

    expect($client)->toHaveCount(3);

    foreach ($client as $span) {
        expect($span->parentSpanId)->toBe($parent->spanId);
    }
});

it('closes the span of the call that failed, and lets the failure through', function () {
    $http = Http::setHandler(scriptedHandler([
        new ConnectException('could not connect', new GuzzleRequest('GET', 'https://down.example/v1/thing')),
    ]));

    expect(fn () => $http->get('https://down.example/v1/thing'))
        ->toThrow(ConnectionException::class);

    Telemetry::span('after the failure', fn () => null);

    $spans = allSpans($this->collector);
    $failed = $spans->firstWhere('name', 'GET down.example');

    expect($failed)->not->toBeNull()
        ->and($failed->status())->toBe(SpanStatus::Error)
        ->and($spans->firstWhere('name', 'after the failure')->parentSpanId)->toBeNull();
});

it('breaks a client span into its transfer phases', function () {
    // Each hop reads its OWN stats here. Laravel only keeps the last hop's on
    // the response, so this is also the only place an earlier hop could get
    // them. The numbers are from a real transfer.
    $http = Http::setHandler(scriptedHandler(
        [new PsrResponse(200, [], 'ok')],
        [
            'namelookup_time_us' => 3100,
            'connect_time_us' => 21500,
            'appconnect_time_us' => 63200,
            'pretransfer_time_us' => 63400,
            'starttransfer_time_us' => 257600,
            'total_time_us' => 284200,
            'primary_ip' => '34.120.54.201',
            'primary_port' => 443,
            'http_version' => 3,
        ],
    ));

    $http->post('https://api.stripe.test/v1/charges');

    $attributes = allSpans($this->collector)->firstWhere('name', 'POST api.stripe.test')->attributes();

    expect($attributes['http.client.dns_ms'])->toBe(3.1)
        ->and($attributes['http.client.tcp_ms'])->toBe(18.4)
        ->and($attributes['http.client.tls_ms'])->toBe(41.7)
        ->and($attributes['http.client.ttfb_ms'])->toBe(194.2)
        ->and($attributes['http.client.transfer_ms'])->toBe(26.6)
        ->and($attributes['network.peer.address'])->toBe('34.120.54.201')
        ->and($attributes['network.protocol.version'])->toBe('2');
});

it('leaves the phases out when the timing instrument is off', function () {
    config()->set('telemetry.instrument.http_client_timing', false);

    $http = Http::setHandler(scriptedHandler(
        [new PsrResponse(200, [], 'ok')],
        ['total_time_us' => 100000, 'connect_time_us' => 5000],
    ));

    $http->get('https://api.stripe.test/v1/charges');

    expect(allSpans($this->collector)->firstWhere('name', 'GET api.stripe.test')->attributes())
        ->not->toHaveKey('http.client.ttfb_ms');
});

it('adds no timing attributes when there was no cURL behind the response', function () {
    // A faked response, and the stream handler: a TransferStats with nothing
    // behind it. Zeroes would read as a transfer that did every phase
    // instantly.
    Http::fake(['*' => Http::response('ok', 200)]);

    Http::get('https://api.stripe.test/v1/charges');

    expect(allSpans($this->collector)->firstWhere('name', 'GET api.stripe.test')->attributes())
        ->not->toHaveKey('http.client.ttfb_ms');
});
