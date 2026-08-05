<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\TelemetryManager;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\File;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Instrumented drop-in for a concrete {@see FilesystemAdapter} disk.
 *
 * It MUST be a real subclass, not a standalone decorator — the same
 * lesson the manager learned in 0.3.1, one level further down. Plenty of
 * code type-hints Laravel's concrete adapter rather than the
 * `Filesystem` contract; Statamic's `Imaging\Attributes::from()` is one,
 * so saving an asset through an instrumented disk died with a TypeError.
 * A decorator that only implemented `Filesystem` could never satisfy
 * that, and `instrument.filesystem` is all-or-nothing, so the only
 * escape was turning disk instrumentation off entirely.
 *
 * Behaviour is delegated to the wrapped disk rather than inherited,
 * everywhere the wrapped disk might not be a plain `FilesystemAdapter`.
 * `AwsS3V3Adapter` and `LocalFilesystemAdapter` both override `url()`,
 * `temporaryUrl()` and friends; running the parent's generic versions
 * instead would silently hand back wrong URLs, which is a worse failure
 * than the TypeError this class fixes. The parent constructor still gets
 * the wrapped disk's own driver, adapter and config, so anything that
 * does reach an inherited method — or calls `getDriver()` — sees exactly
 * what it would have seen without instrumentation.
 *
 * One limit worth stating: a disk backed by a `FilesystemAdapter`
 * *subclass* satisfies `instanceof FilesystemAdapter` through this class
 * but not `instanceof AwsS3V3Adapter`. PHP cannot pick a parent class at
 * runtime. That was equally true before this change (the disk was not
 * even a `FilesystemAdapter` then), so it is strictly an improvement —
 * and `instrument.filesystem_ignore_disks` is the escape hatch for a
 * disk whose consumers need the exact subclass.
 *
 * @internal
 */
class InstrumentedFilesystemAdapter extends FilesystemAdapter
{
    private readonly DiskOperations $operations;

    public function __construct(
        private readonly FilesystemAdapter $disk,
        TelemetryManager $telemetry,
        string $diskName,
    ) {
        parent::__construct($disk->getDriver(), $disk->getAdapter(), $disk->getConfig());

        $this->operations = new DiskOperations($telemetry, $diskName);
    }

    /**
     * The disk this one measures — for callers that need the exact
     * concrete type back (see the class note on adapter subclasses).
     */
    public function uninstrumented(): FilesystemAdapter
    {
        return $this->disk;
    }

    /*
    |--------------------------------------------------------------------------
    | Measured operations
    |--------------------------------------------------------------------------
    |
    | The same set as InstrumentedFilesystem, so the signals an app gets
    | never depend on which decorator its driver happened to land on.
    |
    */

    public function exists($path)
    {
        return $this->operations->record('exists', $path, fn () => $this->disk->exists($path));
    }

    public function get($path)
    {
        return $this->operations->record('get', $path, fn () => $this->disk->get($path));
    }

    public function readStream($path)
    {
        return $this->operations->record('readStream', $path, fn () => $this->disk->readStream($path));
    }

    public function put($path, $contents, $options = [])
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
    public function writeStream($path, $resource, array $options = [])
    {
        return $this->operations->record('writeStream', $path, fn () => $this->disk->writeStream($path, $resource, $options));
    }

    public function getVisibility($path)
    {
        return $this->operations->record('getVisibility', $path, fn () => $this->disk->getVisibility($path));
    }

    public function setVisibility($path, $visibility)
    {
        return $this->operations->record('setVisibility', $path, fn () => $this->disk->setVisibility($path, $visibility));
    }

    public function prepend($path, $data, $separator = PHP_EOL)
    {
        return $this->operations->record('prepend', $path, fn () => $this->disk->prepend($path, $data, $separator));
    }

    public function append($path, $data, $separator = PHP_EOL)
    {
        return $this->operations->record('append', $path, fn () => $this->disk->append($path, $data, $separator));
    }

    /**
     * @param  string|array<int, string>  $paths
     */
    public function delete($paths)
    {
        return $this->operations->record('delete', $this->operations->pathList($paths), fn () => $this->disk->delete($paths));
    }

    public function copy($from, $to)
    {
        return $this->operations->record('copy', "{$from} -> {$to}", fn () => $this->disk->copy($from, $to));
    }

    public function move($from, $to)
    {
        return $this->operations->record('move', "{$from} -> {$to}", fn () => $this->disk->move($from, $to));
    }

    public function size($path)
    {
        return $this->operations->record('size', $path, fn () => $this->disk->size($path));
    }

    public function lastModified($path)
    {
        return $this->operations->record('lastModified', $path, fn () => $this->disk->lastModified($path));
    }

    public function makeDirectory($path)
    {
        return $this->operations->record('makeDirectory', $path, fn () => $this->disk->makeDirectory($path));
    }

    public function deleteDirectory($directory)
    {
        return $this->operations->record('deleteDirectory', $directory, fn () => $this->disk->deleteDirectory($directory));
    }

    /*
    |--------------------------------------------------------------------------
    | Delegated, not measured
    |--------------------------------------------------------------------------
    |
    | Two reasons a method lands here rather than being inherited: the
    | adapter subclasses override it (url/temporaryUrl and friends), or it
    | reads per-instance state the wrapped disk owns (the serve and
    | temporary-url callbacks). Inheriting either would change behaviour.
    |
    */

    public function path($path)
    {
        return $this->disk->path($path);
    }

    public function url($path)
    {
        return $this->disk->url($path);
    }

    public function providesTemporaryUrls()
    {
        return $this->disk->providesTemporaryUrls();
    }

    public function providesTemporaryUploadUrls()
    {
        return $this->disk->providesTemporaryUploadUrls();
    }

    /**
     * @param  array<array-key, mixed>  $options
     */
    public function temporaryUrl($path, $expiration, array $options = [])
    {
        return $this->disk->temporaryUrl($path, $expiration, $options);
    }

    /**
     * @param  array<array-key, mixed>  $options
     * @return array<string, mixed>
     */
    public function temporaryUploadUrl($path, $expiration, array $options = [])
    {
        return $this->disk->temporaryUploadUrl($path, $expiration, $options);
    }

    public function buildTemporaryUrlsUsing(\Closure $callback)
    {
        $this->disk->buildTemporaryUrlsUsing($callback);
    }

    public function buildTemporaryUploadUrlsUsing(\Closure $callback)
    {
        $this->disk->buildTemporaryUploadUrlsUsing($callback);
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    public function serve(Request $request, $path, $name = null, array $headers = [])
    {
        return $this->disk->serve($request, $path, $name, $headers);
    }

    public function serveUsing(\Closure $callback)
    {
        $this->disk->serveUsing($callback);
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    public function response($path, $name = null, array $headers = [], $disposition = 'inline')
    {
        return $this->disk->response($path, $name, $headers, $disposition);
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    public function download($path, $name = null, array $headers = [])
    {
        return $this->disk->download($path, $name, $headers);
    }

    /**
     * @return array<int, string>
     */
    public function files($directory = null, $recursive = false)
    {
        return $this->disk->files($directory, $recursive);
    }

    /**
     * @return array<int, string>
     */
    public function allFiles($directory = null)
    {
        return $this->disk->allFiles($directory);
    }

    /**
     * @return array<int, string>
     */
    public function directories($directory = null, $recursive = false)
    {
        return $this->disk->directories($directory, $recursive);
    }

    /**
     * @return array<int, string>
     */
    public function allDirectories($directory = null)
    {
        return $this->disk->allDirectories($directory);
    }

    /**
     * Adapter extras that live only on a subclass — `getClient()` on the
     * S3 adapter — plus any registered macro, straight to the real disk.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $parameters
     */
    public function __call($method, $parameters)
    {
        return $this->disk->{$method}(...$parameters);
    }
}
