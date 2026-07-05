<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Instrumentation\CommandInstrumentation;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\SpanStatus;
use Illuminate\Auth\GenericUser;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
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

    Telemetry::resolveUserUsing(fn ($user) => ['enduser.name' => $user->name ?? 'unknown']);

    $this->actingAs(new GenericUser(['id' => 9, 'name' => 'Jared']));

    $this->get('/me');

    $span = allSpans($this->collector)->firstWhere('name', 'GET /me');

    expect($span->attributes()['enduser.id'])->toBe('9')
        ->and($span->attributes()['enduser.name'])->toBe('Jared');
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

    expect($families)->toHaveKey('worker.memory.php');

    $sample = $families['worker.memory.php']->samples[0];

    expect($sample->value)->toBeGreaterThan(1_000_000)
        ->and($sample->labels['queue'])->toBe('default')
        ->and($sample->labels['pid'])->toBe((string) getmypid());
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
