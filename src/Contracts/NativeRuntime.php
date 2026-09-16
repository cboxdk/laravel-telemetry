<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Contracts;

/**
 * The `cbox_telemetry` extension (cboxdk/telemetry-native), behind a seam.
 *
 * The extension measures the PHP runtime — a statistical CPU profiler,
 * exact timing for connection establishment and cURL, runtime counters
 * and a signal-safe crash recorder. It knows nothing about Laravel and
 * never touches the network: naming, sampling policy, redaction and
 * export stay on this side of the boundary.
 *
 * An interface rather than five bare function calls because the calls
 * are unmockable global functions in a C extension that is optional by
 * design — every consumer here has to work identically without it, and
 * that is only testable if "without it" is a binding.
 *
 * Implementations never throw: a missing extension, an unknown handle
 * or a shape this version does not recognise all degrade to "nothing
 * recorded" (invariant #1).
 */
interface NativeRuntime
{
    /** Whether the extension is loaded and exposes the API this package calls. */
    public function available(): bool;

    /** The extension version, or null when it is not loaded. */
    public function version(): ?string;

    /**
     * Build mode, timer backend, installed hooks, crash recorder state
     * and the bounds in force. Diagnostics only — no application data.
     *
     * @return array<string, mixed>
     */
    public function status(): array;

    /**
     * Open a unit of work. Returns a handle, or 0 when nothing was opened
     * (extension absent or switched off) — never an error.
     *
     * @param  array<string, scalar>  $context  trace_id, span_id, unit,
     *                                          sampled, profile, period_us,
     *                                          max_depth
     */
    public function begin(array $context): int;

    /**
     * End the unit and return its aggregates, or an empty array when the
     * handle is not the open one.
     *
     * @return array<string, mixed>
     */
    public function finish(int $handle, bool $includeProfile = false, bool $includeStacks = false): array;

    /**
     * Read and remove crash records left behind by processes that died.
     *
     * @return list<array<string, mixed>>
     */
    public function drainCrashes(int $max = 32): array;
}
