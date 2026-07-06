<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\TelemetryManager;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;

/**
 * Factory decorator: wraps whatever `disk()` resolves in an
 * InstrumentedFilesystem, driver-agnostic.
 *
 * Every `Filesystem` operation is ALSO implemented explicitly here,
 * delegating to `$this->disk()->method(...)` — this is what makes the
 * `Storage::put(...)` default-disk shorthand instrumented too, not just
 * explicit `Storage::disk('x')->put(...)` calls (Laravel's own
 * FilesystemManager gets this via `__call()`; explicit methods here
 * instead keep every call properly typed rather than dynamically
 * dispatched).
 *
 * `__call()` is reserved for real manager-level methods beyond the
 * Factory interface — `set()` (crucially what `Storage::fake()` uses to
 * inject a disk), `createLocalDriver()` (which `fake()` also calls
 * directly), `forgetDisk()`, `purge()`, `extend()`, and anything else
 * FilesystemManager adds in the future. An earlier draft routed
 * anything unknown through `disk()`, assuming it must be a disk
 * operation — that broke `Storage::fake()` outright. Forwarding
 * unmatched calls straight to the real manager is the safe default.
 */
final readonly class InstrumentedFilesystemManager implements Factory, Filesystem
{
    public function __construct(
        private Factory $manager,
        private TelemetryManager $telemetry,
    ) {}

    public function disk($name = null): Filesystem
    {
        $disk = $this->manager->disk($name);

        if ($disk instanceof InstrumentedFilesystem) {
            return $disk;
        }

        return new InstrumentedFilesystem($disk, $this->telemetry, $this->diskName($name));
    }

    public function path($path): string
    {
        return $this->disk()->path($path);
    }

    public function exists($path): bool
    {
        return $this->disk()->exists($path);
    }

    public function get($path): ?string
    {
        return $this->disk()->get($path);
    }

    public function readStream($path)
    {
        return $this->disk()->readStream($path);
    }

    public function put($path, $contents, $options = []): bool
    {
        return $this->disk()->put($path, $contents, $options);
    }

    /**
     * @param  File|UploadedFile|array<array-key, mixed>|null  $file
     * @param  mixed  $options
     */
    public function putFile($path, $file = null, $options = [])
    {
        return $this->disk()->putFile($path, $file, $options);
    }

    /**
     * @param  File|UploadedFile|string|array<array-key, mixed>|null  $file
     * @param  string|array<array-key, mixed>|null  $name
     * @param  mixed  $options
     */
    public function putFileAs($path, $file, $name = null, $options = [])
    {
        return $this->disk()->putFileAs($path, $file, $name, $options);
    }

    /**
     * @param  array<array-key, mixed>  $options
     */
    public function writeStream($path, $resource, array $options = []): bool
    {
        return $this->disk()->writeStream($path, $resource, $options);
    }

    public function getVisibility($path): string
    {
        return $this->disk()->getVisibility($path);
    }

    public function setVisibility($path, $visibility): bool
    {
        return $this->disk()->setVisibility($path, $visibility);
    }

    public function prepend($path, $data): bool
    {
        return $this->disk()->prepend($path, $data);
    }

    public function append($path, $data): bool
    {
        return $this->disk()->append($path, $data);
    }

    /**
     * @param  string|array<int, string>  $paths
     */
    public function delete($paths): bool
    {
        return $this->disk()->delete($paths);
    }

    public function copy($from, $to): bool
    {
        return $this->disk()->copy($from, $to);
    }

    public function move($from, $to): bool
    {
        return $this->disk()->move($from, $to);
    }

    public function size($path): int
    {
        return $this->disk()->size($path);
    }

    public function lastModified($path): int
    {
        return $this->disk()->lastModified($path);
    }

    public function files($directory = null, $recursive = false): array
    {
        return $this->disk()->files($directory, $recursive);
    }

    public function allFiles($directory = null): array
    {
        return $this->disk()->allFiles($directory);
    }

    public function directories($directory = null, $recursive = false): array
    {
        return $this->disk()->directories($directory, $recursive);
    }

    public function allDirectories($directory = null): array
    {
        return $this->disk()->allDirectories($directory);
    }

    public function makeDirectory($path): bool
    {
        return $this->disk()->makeDirectory($path);
    }

    public function deleteDirectory($directory): bool
    {
        return $this->disk()->deleteDirectory($directory);
    }

    private function diskName(mixed $name): string
    {
        if (is_string($name) && $name !== '') {
            return $name;
        }

        if ($name instanceof \BackedEnum) {
            return (string) $name->value;
        }

        if ($name instanceof \UnitEnum) {
            return $name->name;
        }

        $default = config('filesystems.default');

        return is_string($default) && $default !== '' ? $default : 'local';
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->manager->{$method}(...$arguments);
    }
}
