<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\TelemetryManager;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemManager;

/**
 * Instrumented drop-in for Laravel's FilesystemManager.
 *
 * It MUST be a real subclass of {@see FilesystemManager}, not a
 * standalone decorator: Laravel aliases `FilesystemManager::class` to the
 * 'filesystem' binding, so any consumer that type-hints
 * `FilesystemManager`, injects it, or registers
 * `afterResolving(FilesystemManager::class, …)` (sentry-laravel's storage
 * integration does exactly this) receives — and type-checks — whatever
 * the 'filesystem' key resolves to. A wrapper that only implemented the
 * `Factory`/`Filesystem` contracts failed that `instanceof` check and
 * crashed the app on boot with a TypeError.
 *
 * All real manager behaviour (driver creation, the disk cache, custom
 * creators, `set()`/`Storage::fake()`, `forgetDisk()`, `purge()`, …) is
 * inherited untouched. The only override is `disk()`: whatever the parent
 * resolves is wrapped in {@see InstrumentedFilesystem} so every operation
 * feeds the `storage.operations{disk,operation}` counter and a detail
 * span. Wrapping at `disk()` (rather than the protected `resolve()`) is
 * deliberate — `Storage::fake()` injects its disk straight into the cache
 * and bypasses `resolve()`, so faked disks are instrumented too. The
 * default-disk shorthand (`Storage::put(...)`) routes through the parent's
 * `@mixin` `__call` to `disk()`, so it is covered as well.
 */
final class InstrumentedFilesystemManager extends FilesystemManager
{
    public function __construct(
        Application $app,
        private readonly TelemetryManager $telemetry,
    ) {
        parent::__construct($app);
    }

    /**
     * @param  \UnitEnum|string|null  $name
     */
    public function disk($name = null): Filesystem
    {
        $disk = parent::disk($name);

        if ($disk instanceof InstrumentedFilesystem) {
            return $disk;
        }

        return new InstrumentedFilesystem($disk, $this->telemetry, $this->diskName($name));
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

        return $this->getDefaultDriver();
    }
}
