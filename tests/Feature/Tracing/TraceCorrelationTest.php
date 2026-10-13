<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Instrumentation\CommandInstrumentation;
use Cbox\Telemetry\Testing\CollectingExporter;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);
});

afterEach(function () {
    putenv('TRACEPARENT');
});

function correlationSpans(CollectingExporter $collector): Collection
{
    return collect($collector->batches())->flatMap(fn ($b) => $b->spans)->values();
}

it('puts a request label on the root span', function () {
    Route::get('/labelled', fn () => 'ok');

    $this->get('/labelled', ['X-Telemetry-Label' => 'before eager<load>']);

    $span = correlationSpans($this->collector)->firstWhere('name', 'GET /labelled');

    expect($span->attributes()['laravel.trace.label'])->toBe('beforeeagerload');
});

it('ignores the label header when disabled', function () {
    config()->set('telemetry.traces.label_header', null);
    Route::get('/labelled', fn () => 'ok');

    $this->get('/labelled', ['X-Telemetry-Label' => 'x']);

    expect(correlationSpans($this->collector)->firstWhere('name', 'GET /labelled')->attributes())
        ->not->toHaveKey('laravel.trace.label');
});

it('hands the trace context to child processes', function () {
    $seen = Telemetry::span('request', fn () => [
        'expected' => Telemetry::traceparent(),
        'child' => trim(Process::run([PHP_BINARY, '-r', 'echo getenv("TRACEPARENT");'])->output()),
    ]);

    expect($seen['child'])->toBe($seen['expected'])
        ->and($seen['child'])->toStartWith('00-');
});

it('lets an explicit TRACEPARENT win', function () {
    $child = Telemetry::span('request', fn () => trim(
        Process::env(['TRACEPARENT' => 'mine'])->run([PHP_BINARY, '-r', 'echo getenv("TRACEPARENT");'])->output(),
    ));

    expect($child)->toBe('mine');
});

it('adds nothing outside a trace', function () {
    expect(trim(Process::run([PHP_BINARY, '-r', 'var_export(getenv("TRACEPARENT"));'])->output()))->toBe('false');
});

it('continues the trace from TRACEPARENT when a command starts', function () {
    $traceId = str_repeat('ab', 16);
    $parentId = str_repeat('cd', 8);
    putenv("TRACEPARENT=00-{$traceId}-{$parentId}-01");

    $instrumentation = new CommandInstrumentation(app());
    $instrumentation->register(app('events'));

    app('events')->dispatch(new CommandStarting('demo:child', new ArrayInput([]), new NullOutput));
    app('events')->dispatch(new CommandFinished('demo:child', new ArrayInput([]), new NullOutput, 0));

    $span = correlationSpans($this->collector)->firstWhere('name', 'artisan demo:child');

    expect($span->traceId)->toBe($traceId)
        ->and($span->parentSpanId)->toBe($parentId);
});

it('reads Laravel Context onto the root span and logs when opted in', function () {
    Telemetry::captureLaravelContext();

    Context::add('tenantId', 42);
    Context::add('flags', ['beta' => true]);
    Context::addHidden('secret', 'nope');

    Telemetry::span('request', function () {
        Log::channel('telemetry')->info('hello');
    });
    Telemetry::flush();

    $root = correlationSpans($this->collector)->firstWhere('name', 'request');

    expect($root->attributes()['laravel.context.tenant_id'])->toBe(42)
        ->and($root->attributes()['laravel.context.flags'])->toBe('{"beta":true}')
        ->and($root->attributes())->not->toHaveKey('laravel.context.secret')
        ->and($root->attributes())->not->toHaveKey('laravel.context.trace_id');
});

it('limits Laravel Context to the listed keys', function () {
    Telemetry::captureLaravelContext(['tenantId']);

    Context::add('tenantId', 42);
    Context::add('other', 'x');

    Telemetry::span('request', fn () => null);
    Telemetry::flush();

    $root = correlationSpans($this->collector)->firstWhere('name', 'request');

    expect($root->attributes()['laravel.context.tenant_id'])->toBe(42)
        ->and($root->attributes())->not->toHaveKey('laravel.context.other');
});

it('does not read Laravel Context by default', function () {
    Context::add('tenantId', 42);

    Telemetry::span('request', fn () => null);
    Telemetry::flush();

    expect(correlationSpans($this->collector)->firstWhere('name', 'request')->attributes())
        ->not->toHaveKey('laravel.context.tenant_id');
});

it('answers with a traceresponse naming the server span', function () {
    Route::get('/traced', fn () => 'ok');

    $response = $this->get('/traced');
    $span = correlationSpans($this->collector)->firstWhere('name', 'GET /traced');

    expect($response->headers->get('traceresponse'))->toBe("00-{$span->traceId}-{$span->spanId}-01");
});

it('sends no traceresponse on publicly cacheable responses', function () {
    Route::get('/cached', fn () => response('ok')->header('Cache-Control', 'public, max-age=60'));

    expect($this->get('/cached')->headers->has('traceresponse'))->toBeFalse();
});

it('lets application code force-sample the active trace', function () {
    Telemetry::span('request', function () {
        // A head decision that dropped it, overruled by the app.
        Telemetry::tracer()->resample(false);
        Telemetry::sample(true);
    });
    Telemetry::flush();

    expect(correlationSpans($this->collector)->firstWhere('name', 'request'))->not->toBeNull();
});

it('drops the active trace on sample(false)', function () {
    Telemetry::span('request', function () {
        Telemetry::sample(false);
    });
    Telemetry::flush();

    expect(correlationSpans($this->collector)->firstWhere('name', 'request'))->toBeNull();
});

it('sends Server-Timing when enabled', function () {
    config()->set('telemetry.traces.server_timing', true);
    Route::get('/timed', function () {
        Telemetry::tracer()->bumpStat('db.query.time_ms', 12.34);
        Telemetry::tracer()->bumpStat('db.query.count', 3);

        return 'ok';
    });

    $header = $this->get('/timed')->headers->get('Server-Timing');

    expect($header)->toContain('db;dur=12.3;desc="3 queries"')
        ->and($header)->toContain('telemetry;dur=')
        ->and($header)->toMatch('/app;dur=\d+\.\d/');
});

it('sends no Server-Timing by default', function () {
    Route::get('/timed', fn () => 'ok');

    expect($this->get('/timed')->headers->has('Server-Timing'))->toBeFalse();
});
