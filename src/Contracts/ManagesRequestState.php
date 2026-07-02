<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Contracts;

/**
 * Instrumentation that holds per-request state between a "before" and an
 * "after" event (a pending cache read, an in-flight HTTP call, an open
 * transaction stack). Under Octane the instrumentation is a long-lived
 * singleton, so a request that dies between the two events would leave
 * a stale entry behind — one that leaks worker memory and could mis-
 * parent the next request's spans.
 *
 * The Octane request/tick boundary calls flushRequestState() to drop
 * anything still half-open. Normal FPM never calls it (the process ends
 * anyway).
 */
interface ManagesRequestState
{
    public function flushRequestState(): void;
}
