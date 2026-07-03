<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\Span;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\View\Engine;

/**
 * Engine decorator that wraps every render in a detail span. Rendering
 * ALWAYS proceeds — telemetry failures around it are swallowed, and
 * unknown method calls (getCompiler(), …) forward to the real engine so
 * packages that poke at engine internals keep working.
 *
 * The wrapped engine is deliberately named `$engine`: Laravel's blade error
 * renderer (BladeMapper::getKnownPaths) is decorator-aware and reflects a
 * wrapped engine's `lastCompiled` through a property named exactly `engine`.
 * Rename it and rendering any view exception fatals with "Property
 * TracingEngine::$lastCompiled does not exist".
 */
final class TracingEngine implements Engine
{
    public function __construct(
        private readonly Engine $engine,
        private readonly Container $container,
    ) {}

    /**
     * @param  string  $path
     * @param  array<string, mixed>  $data
     */
    public function get($path, array $data = []): string
    {
        $span = FailSafe::guard(function () use ($path): ?Span {
            $telemetry = $this->container->make(TelemetryManager::class);

            $telemetry->tracer()->bumpStat('view.render.count', 1);

            if ($telemetry->currentSpan()?->sampled !== true) {
                return null;
            }

            $name = $this->viewName((string) $path);

            return $telemetry->tracer()
                ->startSpan("view {$name}", attributes: [
                    'view.name' => $name,
                    'view.path' => str_replace(base_path().'/', '', (string) $path),
                ])
                ->markDetail();
        });

        try {
            return $this->engine->get($path, $data);
        } finally {
            FailSafe::guard(fn () => $span?->end());
        }
    }

    /** @var list<string>|null */
    private ?array $viewPaths = null;

    /**
     * "resources/views/components/button.blade.php" → "components.button"
     * — the bounded dot notation developers recognize from view(),
     * resolved against every registered view location (packages,
     * namespaces, test fixtures), longest match first.
     */
    private function viewName(string $path): string
    {
        $relative = basename($path);

        foreach ($this->finderPaths() as $viewPath) {
            if (str_starts_with($path, $viewPath.'/')) {
                $relative = substr($path, strlen($viewPath) + 1);

                break;
            }
        }

        $stripped = (string) preg_replace('/\.(blade\.php|php|css|html)$/', '', $relative);

        return str_replace('/', '.', $stripped);
    }

    /**
     * @return list<string>
     */
    private function finderPaths(): array
    {
        return $this->viewPaths ??= FailSafe::guard(function (): array {
            $finder = $this->container->make('view')->getFinder();

            $paths = method_exists($finder, 'getPaths') ? $finder->getPaths() : [];

            if (method_exists($finder, 'getHints')) {
                foreach ($finder->getHints() as $hintPaths) {
                    $paths = [...$paths, ...$hintPaths];
                }
            }

            $paths = array_values(array_filter($paths, is_string(...)));

            // Longest first, so nested locations win over their parents.
            usort($paths, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

            return $paths;
        }) ?? [];
    }

    /**
     * Forward everything else (getCompiler(), forgetCompiledOrNotExpired(),
     * …) to the real engine.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->engine->{$method}(...$arguments);
    }
}
