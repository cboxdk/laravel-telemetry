<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Native;

use Cbox\Telemetry\Contracts\NativeRuntime;
use Cbox\Telemetry\Support\FailSafe;

/**
 * The real thing: the five `cbox_telemetry_*` functions, guarded.
 *
 * Every call is both `function_exists`-checked and wrapped in
 * `FailSafe::guard`. The first covers "the extension is not installed",
 * the second covers "it is installed, but a version whose return shape
 * or argument list is not the one this package was written against" —
 * a distinction that matters because the extension ships separately
 * and is upgraded separately.
 */
final class ExtensionRuntime implements NativeRuntime
{
    public const EXTENSION = 'cbox_telemetry';

    private readonly bool $loaded;

    public function __construct()
    {
        $this->loaded = extension_loaded(self::EXTENSION)
            && function_exists('cbox_telemetry_begin')
            && function_exists('cbox_telemetry_finish');
    }

    public function available(): bool
    {
        return $this->loaded;
    }

    public function version(): ?string
    {
        if (! $this->loaded || ! function_exists('cbox_telemetry_version')) {
            return null;
        }

        return FailSafe::guard(static fn (): string => \cbox_telemetry_version());
    }

    public function status(): array
    {
        if (! $this->loaded || ! function_exists('cbox_telemetry_status')) {
            return [];
        }

        /** @var array<string, mixed>|null $status */
        $status = FailSafe::guard(static fn (): array => \cbox_telemetry_status());

        return $status ?? [];
    }

    public function begin(array $context): int
    {
        if (! $this->loaded) {
            return 0;
        }

        return FailSafe::guard(static fn (): int => \cbox_telemetry_begin($context)) ?? 0;
    }

    public function finish(int $handle, bool $includeProfile = false, bool $includeStacks = false): array
    {
        if (! $this->loaded) {
            return [];
        }

        /** @var array<string, mixed>|null $result */
        $result = FailSafe::guard(
            static fn (): array => \cbox_telemetry_finish($handle, $includeProfile, $includeStacks),
        );

        return $result ?? [];
    }

    public function drainCrashes(int $max = 32): array
    {
        if (! $this->loaded || ! function_exists('cbox_telemetry_drain_crashes')) {
            return [];
        }

        /** @var list<array<string, mixed>>|null $records */
        $records = FailSafe::guard(static function () use ($max): array {
            $records = [];

            foreach (\cbox_telemetry_drain_crashes($max) as $record) {
                if (is_array($record)) {
                    /** @var array<string, mixed> $record */
                    $records[] = $record;
                }
            }

            return $records;
        });

        return $records ?? [];
    }
}
