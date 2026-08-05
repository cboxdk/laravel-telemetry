<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\TelemetryManager;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;

/**
 * Wraps a disk that is NOT a concrete {@see FilesystemAdapter}
 * — a custom driver registered through `Storage::extend()` that returns
 * its own `Filesystem` implementation. Laravel's own drivers all resolve
 * to a `FilesystemAdapter` and get {@see InstrumentedFilesystemAdapter}
 * instead, which preserves that concrete type for consumers that
 * type-hint it.
 *
 * Implements the interface explicitly (so `instanceof Filesystem` still
 * holds) but forwards anything NOT on it via __call, straight to the real
 * disk. The real disk object keeps working exactly as before either way.
 *
 * Measurement lives in {@see DiskOperations}, shared with the adapter
 * decorator so both record identically.
 */
final class InstrumentedFilesystem implements Filesystem
{
    private readonly DiskOperations $operations;

    public function __construct(
        private readonly Filesystem $disk,
        TelemetryManager $telemetry,
        string $diskName,
    ) {
        $this->operations = new DiskOperations($telemetry, $diskName);
    }

    public function path($path): string
    {
        return $this->disk->path($path);
    }

    public function exists($path): bool
    {
        return $this->operations->record('exists', $path, fn () => $this->disk->exists($path));
    }

    public function get($path): ?string
    {
        return $this->operations->record('get', $path, fn () => $this->disk->get($path));
    }

    public function readStream($path)
    {
        return $this->operations->record('readStream', $path, fn () => $this->disk->readStream($path));
    }

    public function put($path, $contents, $options = []): bool
    {
        return $this->operations->record('put', $path, fn () => $this->disk->put($path, $contents, $options));
    }

    /**
     * @param  File|UploadedFile|array<array-key, mixed>|null  $file
     * @param  mixed  $options
     */
    public function putFile($path, $file = null, $options = [])
    {
        return $this->operations->record('putFile', $this->operations->pathLabel($path), fn () => $this->disk->putFile($path, $file, $options));
    }

    /**
     * @param  File|UploadedFile|string|array<array-key, mixed>|null  $file
     * @param  string|array<array-key, mixed>|null  $name
     * @param  mixed  $options
     */
    public function putFileAs($path, $file, $name = null, $options = [])
    {
        return $this->operations->record('putFileAs', $this->operations->pathLabel($path), fn () => $this->disk->putFileAs($path, $file, $name, $options));
    }

    /**
     * @param  array<array-key, mixed>  $options
     */
    public function writeStream($path, $resource, array $options = []): bool
    {
        return $this->operations->record('writeStream', $path, fn () => $this->disk->writeStream($path, $resource, $options));
    }

    public function getVisibility($path): string
    {
        return $this->operations->record('getVisibility', $path, fn () => $this->disk->getVisibility($path));
    }

    public function setVisibility($path, $visibility): bool
    {
        return $this->operations->record('setVisibility', $path, fn () => $this->disk->setVisibility($path, $visibility));
    }

    public function prepend($path, $data): bool
    {
        return $this->operations->record('prepend', $path, fn () => $this->disk->prepend($path, $data));
    }

    public function append($path, $data): bool
    {
        return $this->operations->record('append', $path, fn () => $this->disk->append($path, $data));
    }

    /**
     * @param  string|array<int, string>  $paths
     */
    public function delete($paths): bool
    {
        $path = is_array($paths) ? implode(',', $paths) : $paths;

        return $this->operations->record('delete', $path, fn () => $this->disk->delete($paths));
    }

    public function copy($from, $to): bool
    {
        return $this->operations->record('copy', "{$from} -> {$to}", fn () => $this->disk->copy($from, $to));
    }

    public function move($from, $to): bool
    {
        return $this->operations->record('move', "{$from} -> {$to}", fn () => $this->disk->move($from, $to));
    }

    public function size($path): int
    {
        return $this->operations->record('size', $path, fn () => $this->disk->size($path));
    }

    public function lastModified($path): int
    {
        return $this->operations->record('lastModified', $path, fn () => $this->disk->lastModified($path));
    }

    public function files($directory = null, $recursive = false): array
    {
        return $this->disk->files($directory, $recursive);
    }

    public function allFiles($directory = null): array
    {
        return $this->disk->allFiles($directory);
    }

    public function directories($directory = null, $recursive = false): array
    {
        return $this->disk->directories($directory, $recursive);
    }

    public function allDirectories($directory = null): array
    {
        return $this->disk->allDirectories($directory);
    }

    public function makeDirectory($path): bool
    {
        return $this->operations->record('makeDirectory', $path, fn () => $this->disk->makeDirectory($path));
    }

    public function deleteDirectory($directory): bool
    {
        return $this->operations->record('deleteDirectory', $directory, fn () => $this->disk->deleteDirectory($directory));
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->disk->{$method}(...$arguments);
    }
}
