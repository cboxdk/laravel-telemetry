<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Http\Controllers;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Receives source map uploads from the build pipeline (the
 *
 * @cboxdk/telemetry-browser uploader / Vite plugin), keyed by release, so
 * browser stacks can be symbolicated. Unlike the world-reachable span
 * ingest, uploads come from CI — which CAN hold a secret — so this is
 * bearer-token gated and never accidentally open.
 */
final class SourcemapController
{
    public function __invoke(Request $request, TelemetryManager $telemetry): Response
    {
        $config = (array) config('telemetry.sourcemaps', []);

        abort_unless($telemetry->enabled() && ($config['enabled'] ?? false), 404);

        // Secure by default: a token is required, so the endpoint can never
        // be left accidentally open to poison your symbolication.
        $token = $config['token'] ?? null;
        abort_unless(
            is_string($token) && $token !== '' && hash_equals($token, (string) $request->bearerToken()),
            403,
        );

        FailSafe::guard(function () use ($request, $config) {
            $release = self::slug((string) $request->input('release'));
            $name = basename((string) $request->input('name'));
            $map = $request->input('map');

            if ($release === '' || $name === '' || ! is_string($map)) {
                return;
            }
            if (strlen($map) > (int) ($config['max_bytes'] ?? 20 * 1024 * 1024)) {
                return;
            }
            // Only accept a valid v3 source map.
            $decoded = json_decode($map, true);
            if (! is_array($decoded) || ($decoded['version'] ?? null) !== 3) {
                return;
            }

            $prefix = (string) ($config['prefix'] ?? 'telemetry/sourcemaps');
            Storage::disk((string) ($config['disk'] ?? 'local'))
                ->put("{$prefix}/{$release}/{$name}", $map);
        });

        return new Response('', 204);
    }

    private static function slug(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9._-]/', '', $value);
    }
}
