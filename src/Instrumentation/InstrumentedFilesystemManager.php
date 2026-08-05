<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemAdapter;
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
 * resolves is wrapped — in {@see InstrumentedFilesystemAdapter} when it is
 * a concrete `FilesystemAdapter` (every Laravel driver), otherwise in
 * {@see InstrumentedFilesystem} — so every operation feeds the
 * `storage.operations{disk,operation}` counter and a detail span. The
 * split exists because the decorator has to keep passing the type checks
 * the real disk would; see the adapter class for the full reasoning.
 * Wrapping at `disk()` (rather than the protected `resolve()`) is
 * deliberate — `Storage::fake()` injects its disk straight into the cache
 * and bypasses `resolve()`, so faked disks are instrumented too. The
 * default-disk shorthand (`Storage::put(...)`) routes through the parent's
 * `@mixin` `__call` to `disk()`, so it is covered as well.
 *
 * Deliberately NOT `final` — it replaces the 'filesystem' binding, so a
 * final class would break the standard `Storage::shouldReceive(...)` /
 * `Storage::partialMock()` test pattern (Mockery cannot partial-mock a
 * final class). Laravel's own FilesystemManager is non-final for the same
 * reason. Treat it as internal; do not extend it yourself.
 *
 * @internal
 */
class InstrumentedFilesystemManager extends FilesystemManager
{
    public function __construct(
        Application $app,
        private readonly TelemetryManager $telemetry,
    ) {
        parent::__construct($app);
    }

    /** @var list<string>|null memoized — disk() runs on every Storage call */
    private ?array $ignoredDisks = null;

    /**
     * @param  \UnitEnum|string|null  $name
     */
    public function disk($name = null): Filesystem
    {
        $disk = parent::disk($name);

        if ($disk instanceof InstrumentedFilesystem || $disk instanceof InstrumentedFilesystemAdapter) {
            return $disk;
        }

        $diskName = $this->diskName($name);

        if (in_array($diskName, $this->ignoredDisks(), true)) {
            return $disk;
        }

        // A concrete adapter must stay a concrete adapter: consumers type-hint
        // Laravel's FilesystemAdapter, not just the contract. Anything else is
        // a custom Storage::extend() driver, where the contract is all there
        // is to preserve.
        return $disk instanceof FilesystemAdapter
            ? new InstrumentedFilesystemAdapter($disk, $this->telemetry, $diskName)
            : new InstrumentedFilesystem($disk, $this->telemetry, $diskName);
    }

    /**
     * Disks left alone entirely — the escape hatch for a consumer that
     * needs the exact adapter subclass back, which no decorator can be.
     *
     * @return list<string>
     */
    private function ignoredDisks(): array
    {
        return $this->ignoredDisks ??= FailSafe::guard(function (): array {
            $configured = $this->app->make('config')->get('telemetry.instrument.filesystem_ignore_disks', []);

            return is_array($configured) ? array_values(array_filter($configured, 'is_string')) : [];
        }) ?? [];
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
