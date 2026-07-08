<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Foundation\Application;

/**
 * Driver-agnostic filesystem/storage instrumentation: `storage.operations`
 * counter + a `storage {operation}` detail span per disk operation (put,
 * get, delete, copy, move, …) — local, S3, whatever Flysystem supports.
 *
 * Implemented via Container::extend() on the 'filesystem' string binding
 * — Laravel fires no filesystem events. Unlike broadcasting,
 * FilesystemServiceProvider is NOT a DeferrableProvider, so there's no
 * alias-timing hazard here: extend() targets the real binding key from
 * the start, no forced pre-resolve needed (forcing one was tried for
 * broadcasting and had to be reverted — see BroadcastingInstrumentation).
 *
 * The replacement must be a genuine FilesystemManager subclass (see
 * {@see InstrumentedFilesystemManager}) so `instanceof FilesystemManager`
 * still holds for consumers that type-hint it — hence it is constructed
 * from the application, not from the already-resolved manager instance.
 */
final class FilesystemInstrumentation
{
    public function register(Application $app): void
    {
        FailSafe::guard(function () use ($app): void {
            $app->extend('filesystem', function (Factory $manager) use ($app): Factory {
                if ($manager instanceof InstrumentedFilesystemManager) {
                    return $manager;
                }

                return new InstrumentedFilesystemManager($app, $app->make(TelemetryManager::class));
            });
        });
    }
}
