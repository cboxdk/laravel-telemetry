<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Native;

use Cbox\Telemetry\Contracts\NativeRuntime;

/**
 * What gets bound when the extension is absent or `telemetry.native.enabled`
 * is off: nothing is opened, so nothing has to be unwound anywhere else.
 */
final class NullRuntime implements NativeRuntime
{
    public function available(): bool
    {
        return false;
    }

    public function version(): ?string
    {
        return null;
    }

    public function status(): array
    {
        return [];
    }

    public function begin(array $context): int
    {
        return 0;
    }

    public function finish(int $handle, bool $includeProfile = false, bool $includeStacks = false): array
    {
        return [];
    }

    public function drainCrashes(int $max = 32): array
    {
        return [];
    }
}
