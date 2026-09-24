<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Http\RequestPhases;
use Cbox\Telemetry\Support\FailSafe;
use Illuminate\Routing\Contracts\ControllerDispatcher as ControllerDispatcherContract;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Route;

/**
 * The one boundary Laravel does not announce: middleware ending and the
 * controller beginning.
 *
 * RouteMatched fires before the route's middleware stack runs and
 * RequestHandled after the response exists, so everything between them —
 * the stack, the action, the view — arrives as a single number. This sits
 * exactly on the seam: Route::run() resolves the dispatcher from the
 * container for every controller action, so decorating it marks the
 * moment the stack finished without touching the framework or the
 * pipeline.
 *
 * It measures nothing itself. It moves a boundary and delegates, so a
 * fault here cannot change which controller runs or what it is given.
 */
final class InstrumentedControllerDispatcher implements ControllerDispatcherContract
{
    public function __construct(
        private readonly ControllerDispatcherContract $inner,
        private readonly RequestPhases $phases,
    ) {}

    /**
     * @param  mixed  $controller
     * @param  string  $method
     * @return mixed
     */
    public function dispatch(Route $route, $controller, $method)
    {
        FailSafe::guard(fn () => $this->phases->controllerDispatching());

        return $this->inner->dispatch($route, $controller, $method);
    }

    /**
     * @param  Controller  $controller
     * @param  string  $method
     * @return array<int, mixed>
     */
    public function getMiddleware($controller, $method)
    {
        return $this->inner->getMiddleware($controller, $method);
    }
}
