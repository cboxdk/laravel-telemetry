<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Http;

use Cbox\Telemetry\Contracts\ManagesRequestState;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\Tracer;

/**
 * Where a request's time went, phase by phase.
 *
 * The request span starts in the middleware and ends in its terminate(),
 * which Laravel runs AFTER the response has been sent — behind the session
 * save and every defer() callback. So the span alone can't tell a slow
 * response from slow cleanup. Framework events mark the boundaries:
 *
 *   laravel.routing    middleware → RouteMatched
 *   laravel.handler    → RequestHandled (route middleware, controller, views)
 *   laravel.send       → Terminating (the response going out)
 *   laravel.terminate  → the middleware's terminate() (session save,
 *                        defer() callbacks, terminable middleware)
 *
 * Each phase is recorded when it ends: a detail span under the request
 * span, plus a `<phase>_ms` tally on the request span that tail sampling
 * never trims. A boundary that never fires (a 404 matches no route) folds
 * its phase into the next one, which then starts at the last boundary seen.
 *
 * A singleton: the Terminating event carries no request, so the state
 * can't ride on the request's attributes. It has no dependencies of its
 * own because the listeners resolve it at boot, before a host or test
 * has finished binding the rest of the package.
 */
final class RequestPhases implements ManagesRequestState
{
    private ?Span $root = null;

    private ?Tracer $tracer = null;

    /** The last boundary passed, as microtime(true). */
    private float $boundary = 0.0;

    private ?float $sentAt = null;

    /** @var array<string, true> */
    private array $passed = [];

    /**
     * The request span is open — phases are measured from now.
     */
    public function start(Span $root, Tracer $tracer): void
    {
        $this->root = $root;
        $this->tracer = $tracer;
        $this->boundary = microtime(true);
        $this->sentAt = null;
        $this->passed = [];
    }

    public function routeMatched(): void
    {
        $this->pass('laravel.routing');
    }

    public function handled(): void
    {
        $this->pass('laravel.handler');
    }

    public function terminating(): void
    {
        if ($this->pass('laravel.send')) {
            $this->sentAt = $this->boundary;
        }
    }

    /**
     * Close the last phase, and say how long the client waited for its
     * response in ms — null when the send was never observed. Call before
     * the request span ends; the phases are forgotten afterwards.
     */
    public function finish(): ?float
    {
        $root = $this->root;
        $sentAt = $this->sentAt;

        $this->pass('laravel.terminate');
        $this->flushRequestState();

        if ($root === null || $sentAt === null) {
            return null;
        }

        return max(0.0, $sentAt * 1000 - $root->startUnixNano() / 1_000_000);
    }

    public function flushRequestState(): void
    {
        $this->root = null;
        $this->tracer = null;
        $this->sentAt = null;
        $this->passed = [];
    }

    /**
     * Record the phase that ends at this boundary. False when no request
     * span is open (an ignored path, a console process) or the phase was
     * already recorded: a sub-request dispatched through the kernel
     * re-fires these events, and the first boundary wins.
     */
    private function pass(string $phase): bool
    {
        if ($this->root === null || $this->tracer === null || isset($this->passed[$phase])) {
            return false;
        }

        $this->passed[$phase] = true;

        $now = microtime(true);
        $ms = max(0.0, ($now - $this->boundary) * 1000);
        $this->boundary = $now;

        $this->tracer->recordSpan($phase, $ms, detail: true);
        $this->root->setAttribute($phase.'_ms', round($ms, 2));

        return true;
    }
}
