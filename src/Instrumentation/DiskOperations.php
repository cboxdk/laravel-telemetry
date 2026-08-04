<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\Span;
use Closure;
use Throwable;

/**
 * The measuring half of disk instrumentation, split out so the two
 * decorators — {@see InstrumentedFilesystem} for any `Filesystem`, and
 * {@see InstrumentedFilesystemAdapter} for a concrete `FilesystemAdapter`
 * — record byte-for-byte the same thing. If this lived in either class,
 * the signals an app got would quietly depend on which driver its disk
 * happened to resolve to.
 *
 * Paths are safe on spans (per-occurrence, never aggregated) but never
 * become metric labels — same rule as query text and cache keys
 * elsewhere in this package.
 *
 * @internal
 */
final class DiskOperations
{
    public function __construct(
        private readonly TelemetryManager $telemetry,
        private readonly string $diskName,
    ) {}

    /**
     * Run a disk operation inside a detail span. The work always runs —
     * telemetry never blocks or suppresses the actual file operation.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function record(string $name, string $path, Closure $work): mixed
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

    /**
     * putFile()/putFileAs() accept a File/UploadedFile in $path itself
     * (the single-arg form) — a readable label either way.
     */
    public function pathLabel(mixed $path): string
    {
        return is_string($path) ? $path : get_debug_type($path);
    }

    /**
     * @param  string|array<int, string>  $paths
     */
    public function pathList(string|array $paths): string
    {
        return is_array($paths) ? implode(',', $paths) : $paths;
    }
}
