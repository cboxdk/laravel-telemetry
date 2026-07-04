<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Http\Controllers;

use Cbox\Telemetry\TelemetryManager;
use Illuminate\Http\Response;

/**
 * Serves the zero-dependency browser RUM script (resources/js/browser.js)
 * with long cache headers. Only mounted when the span ingest is enabled.
 */
final class BrowserAssetController
{
    public function __invoke(TelemetryManager $telemetry): Response
    {
        abort_unless($telemetry->enabled() && config('telemetry.ingest.spans.enabled', false), 404);

        $js = (string) file_get_contents(__DIR__.'/../../../resources/js/browser.js');

        return new Response($js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
