<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\Span;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Wraps a single disk: every operation inside a detail span, always run
 * — telemetry never blocks or suppresses the actual file operation.
 * Driver-agnostic (local, s3, ftp, …), since `Filesystem` is Flysystem's
 * own abstraction over all of them.
 *
 * Implements the interface explicitly (so `instanceof Filesystem` still
 * holds) but forwards anything NOT on it — adapter-specific extras like
 * `url()`/`temporaryUrl()` — via __call, straight to the real disk. The
 * real disk object keeps working exactly as before either way.
 *
 * Paths are safe on spans (per-occurrence, never aggregated) but never
 * become metric labels — same rule as query text and cache keys
 * elsewhere in this package.
 */
final class InstrumentedFilesystem implements Filesystem
{
    public function __construct(
        private readonly Filesystem $disk,
        private readonly TelemetryManager $telemetry,
        private readonly string $diskName,
    ) {}

    public function path($path): string
    {
        return $this->disk->path($path);
    }

    public function exists($path): bool
    {
        return $this->operation('exists', $path, fn () => $this->disk->exists($path));
    }

    public function get($path): ?string
    {
        return $this->operation('get', $path, fn () => $this->disk->get($path));
    }

    public function readStream($path)
    {
        return $this->operation('readStream', $path, fn () => $this->disk->readStream($path));
    }

    public function put($path, $contents, $options = []): bool
    {
        return $this->operation('put', $path, fn () => $this->disk->put($path, $contents, $options));
    }

    /**
     * @param  File|UploadedFile|array<array-key, mixed>|null  $file
     * @param  mixed  $options
     */
    public function putFile($path, $file = null, $options = [])
    {
        return $this->operation('putFile', $this->pathLabel($path), fn () => $this->disk->putFile($path, $file, $options));
    }

    /**
     * @param  File|UploadedFile|string|array<array-key, mixed>|null  $file
     * @param  string|array<array-key, mixed>|null  $name
     * @param  mixed  $options
     */
    public function putFileAs($path, $file, $name = null, $options = [])
    {
        return $this->operation('putFileAs', $this->pathLabel($path), fn () => $this->disk->putFileAs($path, $file, $name, $options));
    }

    /**
     * @param  array<array-key, mixed>  $options
     */
    public function writeStream($path, $resource, array $options = []): bool
    {
        return $this->operation('writeStream', $path, fn () => $this->disk->writeStream($path, $resource, $options));
    }

    public function getVisibility($path): string
    {
        return $this->operation('getVisibility', $path, fn () => $this->disk->getVisibility($path));
    }

    public function setVisibility($path, $visibility): bool
    {
        return $this->operation('setVisibility', $path, fn () => $this->disk->setVisibility($path, $visibility));
    }

    public function prepend($path, $data): bool
    {
        return $this->operation('prepend', $path, fn () => $this->disk->prepend($path, $data));
    }

    public function append($path, $data): bool
    {
        return $this->operation('append', $path, fn () => $this->disk->append($path, $data));
    }

    /**
     * @param  string|array<int, string>  $paths
     */
    public function delete($paths): bool
    {
        $path = is_array($paths) ? implode(',', $paths) : $paths;

        return $this->operation('delete', $path, fn () => $this->disk->delete($paths));
    }

    public function copy($from, $to): bool
    {
        return $this->operation('copy', "{$from} -> {$to}", fn () => $this->disk->copy($from, $to));
    }

    public function move($from, $to): bool
    {
        return $this->operation('move', "{$from} -> {$to}", fn () => $this->disk->move($from, $to));
    }

    public function size($path): int
    {
        return $this->operation('size', $path, fn () => $this->disk->size($path));
    }

    public function lastModified($path): int
    {
        return $this->operation('lastModified', $path, fn () => $this->disk->lastModified($path));
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
        return $this->operation('makeDirectory', $path, fn () => $this->disk->makeDirectory($path));
    }

    public function deleteDirectory($directory): bool
    {
        return $this->operation('deleteDirectory', $directory, fn () => $this->disk->deleteDirectory($directory));
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->disk->{$method}(...$arguments);
    }

    /**
     * putFile()/putFileAs() accept a File/UploadedFile in $path itself
     * (the single-arg form) — a readable label either way.
     */
    private function pathLabel(mixed $path): string
    {
        return is_string($path) ? $path : get_debug_type($path);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function operation(string $name, string $path, Closure $work): mixed
    {
        $span = FailSafe::guard(function () use ($name, $path): ?Span {
            $labels = ['disk' => $this->diskName, 'operation' => $name];

            $this->telemetry->tracer()->bumpStat('storage.operation.count', 1);
            $this->telemetry->counter('storage.operations', 'Filesystem/disk operations')->inc(1, $labels);

            if ($this->telemetry->currentSpan()?->sampled !== true) {
                return null;
            }

            return $this->telemetry->tracer()->startSpan("storage {$name}", attributes: [
                'storage.disk' => $this->diskName,
                'storage.operation' => $name,
                'storage.path' => $path,
            ])->markDetail();
        });

        try {
            return $work();
        } catch (Throwable $e) {
            FailSafe::guard(function () use ($span, $e): void {
                $span?->recordException($e);
            });

            throw $e;
        } finally {
            FailSafe::guard(fn () => $span?->end());
        }
    }
}
