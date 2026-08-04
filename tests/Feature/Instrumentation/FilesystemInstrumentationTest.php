<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Testing\CollectingExporter;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
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

/**
 * The shape of Statamic's Imaging\Attributes::from() — a consumer that
 * type-hints Laravel's concrete adapter rather than the Filesystem
 * contract. Saving an asset through an instrumented disk used to die
 * here with a TypeError.
 */
final class AdapterTypeHintingConsumer
{
    public static function from(FilesystemAdapter $source): string
    {
        return $source::class;
    }
}

it('hands a real FilesystemAdapter to consumers that type-hint the concrete class', function () {
    $disk = Storage::disk('local');

    expect(fn () => AdapterTypeHintingConsumer::from($disk))->not->toThrow(TypeError::class);

    expect($disk)->toBeInstanceOf(FilesystemAdapter::class)
        // …without losing the contract, which everything else relies on.
        ->and($disk)->toBeInstanceOf(Filesystem::class);
});

it('keeps instrumenting the disk it now type-checks as', function () {
    // The type fix must not have been bought by quietly dropping the
    // instrumentation on Laravel's own drivers.
    Telemetry::span('root', fn () => Storage::disk('local')->put('typed.txt', 'x'));
    Telemetry::flush();

    expect(storageSpans($this->collector))->toHaveCount(1)
        ->and(collect(storageFamilies()['storage.operations']->samples)->first()->value)->toBe(1.0);
});

it('keeps adapter extras and macros working through the decorator', function () {
    // url() lives on FilesystemAdapter, not the Filesystem contract, and
    // the adapter subclasses override it — so it has to reach the real
    // disk rather than an inherited generic implementation.
    expect(Storage::disk('local')->url('a.txt'))->toBeString();

    FilesystemAdapter::macro('cboxTestMacro', fn (): string => 'macro ran');

    expect(Storage::disk('local')->cboxTestMacro())->toBe('macro ran');
});

it('routes temporary-url callbacks to the disk that will read them back', function () {
    // buildTemporaryUrlsUsing() writes per-instance state that
    // temporaryUrl() reads. If one is delegated and the other inherited,
    // the callback is stored on an object nobody asks.
    $disk = Storage::disk('local');
    $disk->buildTemporaryUrlsUsing(fn (string $path) => "https://cdn.test/{$path}");

    expect($disk->temporaryUrl('a.txt', now()->addMinute()))->toBe('https://cdn.test/a.txt');
});

it('still wraps a custom driver that is not a FilesystemAdapter', function () {
    // Storage::extend() may return any Filesystem. There is no concrete
    // type to preserve, so the generic decorator applies — but the
    // operation must still be counted.
    Storage::extend('cbox-null', fn () => new class implements Filesystem
    {
        public array $written = [];

        public function path($path): string
        {
            return $path;
        }

        public function exists($path): bool
        {
            return isset($this->written[$path]);
        }

        public function get($path): ?string
        {
            return $this->written[$path] ?? null;
        }

        public function readStream($path)
        {
            return null;
        }

        public function put($path, $contents, $options = []): bool
        {
            $this->written[$path] = $contents;

            return true;
        }

        public function putFile($path, $file = null, $options = [])
        {
            return false;
        }

        public function putFileAs($path, $file, $name = null, $options = [])
        {
            return false;
        }

        public function writeStream($path, $resource, array $options = []): bool
        {
            return true;
        }

        public function getVisibility($path): string
        {
            return 'public';
        }

        public function setVisibility($path, $visibility): bool
        {
            return true;
        }

        public function prepend($path, $data): bool
        {
            return true;
        }

        public function append($path, $data): bool
        {
            return true;
        }

        public function delete($paths): bool
        {
            return true;
        }

        public function copy($from, $to): bool
        {
            return true;
        }

        public function move($from, $to): bool
        {
            return true;
        }

        public function size($path): int
        {
            return 0;
        }

        public function lastModified($path): int
        {
            return 0;
        }

        public function files($directory = null, $recursive = false): array
        {
            return [];
        }

        public function allFiles($directory = null): array
        {
            return [];
        }

        public function directories($directory = null, $recursive = false): array
        {
            return [];
        }

        public function allDirectories($directory = null): array
        {
            return [];
        }

        public function makeDirectory($path): bool
        {
            return true;
        }

        public function deleteDirectory($directory): bool
        {
            return true;
        }
    });

    config()->set('filesystems.disks.custom', ['driver' => 'cbox-null']);

    $disk = Storage::disk('custom');

    expect($disk)->toBeInstanceOf(Filesystem::class)
        ->and($disk)->not->toBeInstanceOf(FilesystemAdapter::class);

    $disk->put('x.txt', 'hello');

    $samples = collect(storageFamilies()['storage.operations']->samples)
        ->keyBy(fn ($s) => $s->labels['disk']);

    expect($samples['custom']->value)->toBe(1.0)
        ->and($disk->get('x.txt'))->toBe('hello');
});

it('leaves a disk alone entirely when it is on the ignore list', function () {
    // The escape hatch for a consumer that needs an exact adapter
    // subclass, which no decorator can be.
    config()->set('telemetry.instrument.filesystem_ignore_disks', ['local']);
    $this->app->forgetInstance('filesystem');
    Storage::clearResolvedInstances();
    Storage::fake('local');
    Storage::fake('other');

    Storage::disk('local')->put('ignored.txt', 'x');
    Storage::disk('other')->put('watched.txt', 'x');

    $samples = collect(storageFamilies()['storage.operations']->samples)
        ->keyBy(fn ($s) => $s->labels['disk']);

    expect($samples)->not->toHaveKey('local')
        ->and($samples['other']->value)->toBe(1.0);
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
