<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
use Illuminate\Contracts\Container\Container;
use Illuminate\View\Engines\EngineResolver;

/**
 * View render instrumentation: every Blade/PHP view, partial and
 * component renders inside its own span — real durations, naturally
 * nested (a partial's span is a child of the including view's span).
 *
 * Implemented by decorating the resolved engines, since Laravel fires
 * no "rendered" event with timing. Spans are detail-marked, so tail
 * mode keeps them only for failing/slow traces, and only recorded
 * inside a sampled trace. The request root span carries a
 * `view.render.count` tally either way.
 */
final class ViewInstrumentation
{
    private const ENGINES = ['file', 'php', 'blade'];

    public function register(Container $container): void
    {
        FailSafe::guard(function () use ($container) {
            $resolver = $container->make('view.engine.resolver');

            if (! $resolver instanceof EngineResolver) {
                return;
            }

            foreach (self::ENGINES as $name) {
                // Resolve the original FIRST, then re-register a wrapper
                // around that instance — register() forgets the cached
                // engine, so ordering matters.
                try {
                    $engine = $resolver->resolve($name);
                } catch (\InvalidArgumentException) {
                    continue; // engine not registered in this app
                }

                if ($engine instanceof TracingEngine) {
                    continue; // idempotent — Octane workers re-boot providers
                }

                $resolver->register($name, fn () => new TracingEngine($engine, $container));
            }
        });
    }
}
