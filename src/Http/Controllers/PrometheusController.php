<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Http\Controllers;

use Cbox\Telemetry\Exporters\Prometheus\PrometheusRenderer;
use Cbox\Telemetry\Metrics\MetricFamily;
use Cbox\Telemetry\TelemetryManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The Prometheus scrape endpoint: renders the shared metric store plus
 * observable gauges, evaluated at scrape time. No collector involved.
 */
final class PrometheusController
{
    public function __invoke(Request $request, TelemetryManager $telemetry, PrometheusRenderer $renderer): Response
    {
        abort_unless($telemetry->enabled() && config('telemetry.prometheus.enabled'), 404);

        $endpoint = (string) ($request->route()?->defaults['telemetryEndpoint'] ?? 'default');

        /** @var list<string>|null $only */
        $only = config("telemetry.prometheus.endpoints.{$endpoint}.only");

        $families = $telemetry->collect();

        if ($only !== null) {
            $families = array_values(array_filter(
                $families,
                fn (MetricFamily $family): bool => $this->matches($family->name(), $only),
            ));
        }

        return new Response($renderer->render($families), 200, [
            'Content-Type' => PrometheusRenderer::MIME_TYPE,
        ]);
    }

    /**
     * @param  list<string>  $prefixes
     */
    private function matches(string $name, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($name === $prefix || str_starts_with($name, rtrim($prefix, '.').'.')) {
                return true;
            }
        }

        return false;
    }
}
