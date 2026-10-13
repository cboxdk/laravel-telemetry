<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Http;

/**
 * The vendored @cboxdk/telemetry-browser standalone bundle, loaded by
 * the `@telemetryBrowser` directive. It is the npm release's own
 * dist/telemetry.global.js (built from the SDK's git tag, with provenance),
 * copied byte for byte apart from a license banner — never a CDN request.
 *
 * Refresh with `composer browser-sdk -- <version>`
 * (bin/update-browser-sdk.php), which rewrites the bundle, its manifest
 * (resources/js/browser-sdk.json) and VERSION below.
 */
final class BrowserSdk
{
    /** The npm package the bundle comes from. */
    public const PACKAGE = '@cboxdk/telemetry-browser';

    /** The bundled SDK version — also the asset URL's cache-busting `?v=`. */
    public const VERSION = '0.4.0';

    /** The bundle on disk. */
    public static function path(): string
    {
        return dirname(__DIR__, 2).'/resources/js/browser.js';
    }
}
