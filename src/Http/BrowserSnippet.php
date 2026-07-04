<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Http;

/**
 * Renders the @telemetryBrowser output: the traceparent meta (so the
 * browser roots on the server trace) plus the RUM <script> tag configured
 * via data-* attributes. Empty when the ingest is disabled.
 */
final class BrowserSnippet
{
    public static function render(): string
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('telemetry.ingest.spans', []);

        if (! ($config['enabled'] ?? false)) {
            return '';
        }

        /** @var array<string, mixed> $browser */
        $browser = (array) ($config['browser'] ?? []);

        $traceparent = app('telemetry')->traceparent();
        $meta = is_string($traceparent) ? '<meta name="traceparent" content="'.e($traceparent).'">' : '';

        $asset = url((string) ($config['asset_path'] ?? 'telemetry/browser.js'));
        $endpoint = url((string) ($config['path'] ?? 'telemetry/spans'));

        return $meta.'<script src="'.e($asset).'" defer'
            .' data-endpoint="'.e($endpoint).'"'
            .' data-fetch="'.(($browser['fetch'] ?? true) ? '1' : '0').'"'
            .' data-errors="'.(($browser['errors'] ?? true) ? '1' : '0').'"'
            .' data-sample="'.e((string) ($browser['sample'] ?? 1.0)).'"></script>';
    }
}
