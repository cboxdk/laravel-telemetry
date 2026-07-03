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

        return new Response($renderer->render($families, $this->resourceLabels($telemetry)), 200, [
            'Content-Type' => PrometheusRenderer::MIME_TYPE,
        ]);
    }

    /**
     * The stable resource identity, as Prometheus labels stamped on every
     * scraped series — so a single Prometheus scraping many apps (or many
     * hosts of one app) can filter by service/environment/host, matching
     * what OTLP push carries. Churny attrs (deploy id, version) are left
     * off to avoid per-deploy series turnover.
     *
     * @return array<string, string>
     */
    private function resourceLabels(TelemetryManager $telemetry): array
    {
        $resource = $telemetry->resource();
        $map = [
            'service.name' => 'service_name',
            'service.namespace' => 'service_namespace',
            'deployment.environment.name' => 'deployment_environment_name',
            'host.name' => 'host_name',
        ];

        $labels = [];

        foreach ($map as $key => $label) {
            if (isset($resource[$key]) && is_scalar($resource[$key]) && (string) $resource[$key] !== '') {
                $labels[$label] = (string) $resource[$key];
            }
        }

        return $labels;
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
