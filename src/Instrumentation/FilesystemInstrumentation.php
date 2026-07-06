<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Factory;

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
 */
final class FilesystemInstrumentation
{
    public function register(Container $container): void
    {
        FailSafe::guard(function () use ($container): void {
            $container->extend('filesystem', function (Factory $manager, Container $app) {
                if ($manager instanceof InstrumentedFilesystemManager) {
                    return $manager;
                }

                return new InstrumentedFilesystemManager($manager, $app->make(TelemetryManager::class));
            });
        });
    }
}
