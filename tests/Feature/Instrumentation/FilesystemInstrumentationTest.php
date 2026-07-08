<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Testing\CollectingExporter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Mockery\Exception;

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);

    Storage::fake('local');
});

function storageFamilies(): Collection
{
    return collect(Telemetry::collect())->keyBy(fn ($f) => $f->name());
}

function storageSpans(CollectingExporter $collector): array
{
    return collect($collector->batches())
        ->flatMap(fn ($batch) => $batch->spans)
        ->filter(fn ($span) => str_starts_with($span->name, 'storage '))
        ->values()
        ->all();
}

it('spans a put() inside a sampled trace, tallying the root span regardless', function () {
    Telemetry::span('root', function () {
        Storage::disk('local')->put('reports/q1.csv', 'a,b,c');
    });
    Telemetry::flush();

    $spans = storageSpans($this->collector);

    expect($spans)->toHaveCount(1)
        ->and($spans[0]->name)->toBe('storage put')
        ->and($spans[0]->attributes()['storage.disk'])->toBe('local')
        ->and($spans[0]->attributes()['storage.operation'])->toBe('put')
        ->and($spans[0]->attributes()['storage.path'])->toBe('reports/q1.csv')
        ->and($spans[0]->isDetail())->toBeTrue();

    $root = collect($this->collector->batches())->flatMap(fn ($batch) => $batch->spans)
        ->first(fn ($span) => $span->parentSpanId === null);

    expect($root->attributes()['storage.operation.count'])->toBe(1);
});

it('counts operations by disk and operation name', function () {
    Storage::disk('local')->put('a.txt', 'x');
    Storage::disk('local')->get('a.txt');
    Storage::disk('local')->delete('a.txt');

    $samples = collect(storageFamilies()['storage.operations']->samples)
        ->keyBy(fn ($s) => $s->labels['operation']);

    expect($samples['put']->labels['disk'])->toBe('local')
        ->and($samples['put']->value)->toBe(1.0)
        ->and($samples['get']->value)->toBe(1.0)
        ->and($samples['delete']->value)->toBe(1.0);
});

it('instruments the default-disk shorthand too, not just Storage::disk()', function () {
    Telemetry::span('root', function () {
        Storage::put('shorthand.txt', 'x');
    });
    Telemetry::flush();

    expect(storageSpans($this->collector))->toHaveCount(1);
});

it('still performs the real operation even when telemetry is active', function () {
    Storage::disk('local')->put('real.txt', 'hello world');

    expect(Storage::disk('local')->get('real.txt'))->toBe('hello world')
        ->and(Storage::disk('local')->exists('real.txt'))->toBeTrue();
});

it('creates no detail span outside a sampled trace, but keeps the tally', function () {
    Storage::disk('local')->put('a.txt', 'x');
    Telemetry::flush();

    expect(storageSpans($this->collector))->toBeEmpty();
});

it('stays a real FilesystemManager for afterResolving(FilesystemManager::class) consumers', function () {
    // The exact shape of sentry-laravel's storage integration: a typed
    // afterResolving callback on FilesystemManager::class (aliased to the
    // 'filesystem' binding). Before the fix the instrumented manager was a
    // standalone wrapper, not a FilesystemManager subclass, so this callback
    // fired with the wrong type and crashed the app on boot with a TypeError.
    $received = null;

    $this->app->forgetInstance('filesystem');
    Storage::clearResolvedInstances();

    $this->app->afterResolving(
        FilesystemManager::class,
        function (FilesystemManager $manager) use (&$received): void {
            $received = $manager;
        }
    );

    // Resolving 'filesystem' now runs the extender, then fires the typed
    // afterResolving callback — the boot sequence that used to TypeError.
    Storage::fake('local');

    // (a) no TypeError above, and (b) the binding is a real FilesystemManager.
    expect($received)->toBeInstanceOf(FilesystemManager::class)
        ->and($this->app->make('filesystem'))->toBeInstanceOf(FilesystemManager::class);

    // (c) instrumentation still records once a disk operation runs.
    Storage::disk('local')->put('after-resolving.txt', 'x');

    $samples = collect(storageFamilies()['storage.operations']->samples)
        ->keyBy(fn ($s) => $s->labels['operation']);

    expect($samples['put']->value)->toBe(1.0)
        ->and($samples['put']->labels['disk'])->toBe('local');
});

it('supports Storage::shouldReceive() / partialMock() on the instrumented binding', function () {
    // The instrumented manager replaces the 'filesystem' binding, so it must
    // stay non-final: Facade::shouldReceive() / partialMock() build a Mockery
    // partial mock of the resolved instance, which fails outright on a final
    // class ("marked final and its methods cannot be replaced"). This is the
    // standard app testing pattern and must keep working.
    expect(fn () => Storage::partialMock())->not->toThrow(Exception::class);

    expect(fn () => Storage::shouldReceive('exists')->with('x')->andReturnTrue())
        ->not->toThrow(Exception::class);

    expect(Storage::exists('x'))->toBeTrue();
});

it('records an exception and rethrows on a failing operation', function () {
    Storage::fake('local', ['throw' => true]);

    Telemetry::span('root', function () {
        try {
            Storage::disk('local')->readStream('missing/does-not-exist.txt');

            expect(false)->toBeTrue('expected readStream to throw for a missing file');
        } catch (Throwable) {
            // expected — the disk is configured to throw on failure
        }
    });
    Telemetry::flush();

    $spans = storageSpans($this->collector);

    expect($spans)->toHaveCount(1)
        ->and($spans[0]->name)->toBe('storage readStream');
});
