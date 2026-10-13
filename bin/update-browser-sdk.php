#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Vendor a @cboxdk/telemetry-browser release as the @telemetryBrowser bundle.
 *
 *   composer browser-sdk -- 0.3.0
 *   php bin/update-browser-sdk.php 0.3.0
 *
 * Takes the standalone build (dist/telemetry.global.js) out of the npm
 * release tarball. That tarball is built by the SDK's release workflow from
 * its git tag and carries an npm provenance attestation, so the bundle is
 * the tag's build, not a local one. The tarball's sha512 is checked
 * against the registry's integrity before anything is written.
 *
 * Writes:
 *   resources/js/browser.js        the bundle, with a license banner and
 *                                  no sourceMappingURL (the map isn't served)
 *   resources/js/browser-sdk.json  version, source commit, integrity, the
 *                                  bundle's sha256 and bundled dependencies
 *   src/Http/BrowserSdk.php        the VERSION constant
 *
 * Then regenerate the SBOM (`composer sbom`) and add a CHANGELOG entry.
 */
$root = dirname(__DIR__);
$package = '@cboxdk/telemetry-browser';
$version = $argv[1] ?? '';

if (preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $version) !== 1) {
    fwrite(STDERR, "Usage: php bin/update-browser-sdk.php <version>   e.g. 0.3.0\n");
    exit(1);
}

function fetch(string $url): string
{
    $body = @file_get_contents($url, false, stream_context_create([
        'http' => ['timeout' => 30, 'header' => "User-Agent: cboxdk/laravel-telemetry\r\n"],
    ]));

    if (! is_string($body)) {
        fwrite(STDERR, "Could not download {$url}\n");
        exit(1);
    }

    return $body;
}

/** @var array{gitHead?: string, license?: string, dist: array{tarball: string, integrity: string}} $meta */
$meta = json_decode(fetch('https://registry.npmjs.org/'.str_replace('/', '%2f', $package).'/'.$version), true, 512, JSON_THROW_ON_ERROR);

$tarball = fetch($meta['dist']['tarball']);
$integrity = 'sha512-'.base64_encode(hash('sha512', $tarball, true));

if (! hash_equals($meta['dist']['integrity'], $integrity)) {
    fwrite(STDERR, "Integrity mismatch for {$package}@{$version}: registry {$meta['dist']['integrity']}, got {$integrity}\n");
    exit(1);
}

$tmp = sys_get_temp_dir().'/telemetry-browser-'.bin2hex(random_bytes(6)).'.tgz';
file_put_contents($tmp, $tarball);

try {
    $bundle = (new PharData($tmp))['package/dist/telemetry.global.js']->getContent();
} finally {
    @unlink($tmp);
}

// The source map isn't vendored, so don't point DevTools at a 404.
$bundle = rtrim((string) preg_replace('~\n?//# sourceMappingURL=\S+~', '', $bundle))."\n";

// What the bundle inlines, as resolved by the SDK's lockfile at the
// published commit.
$commit = $meta['gitHead'] ?? 'v'.$version;
$bundled = [];

/** @var array{packages?: array<string, array{version?: string, license?: string}>} $lock */
$lock = json_decode(fetch("https://raw.githubusercontent.com/cboxdk/telemetry-browser/{$commit}/package-lock.json"), true, 512, JSON_THROW_ON_ERROR);

foreach (['web-vitals' => 'Apache-2.0'] as $dependency => $fallbackLicense) {
    $entry = $lock['packages']['node_modules/'.$dependency] ?? null;
    if ($entry !== null) {
        $bundled[] = [
            'name' => $dependency,
            'version' => (string) ($entry['version'] ?? ''),
            'license' => (string) ($entry['license'] ?? $fallbackLicense),
        ];
    }
}

$notices = implode('; ', array_map(
    static fn (array $d): string => "{$d['name']} {$d['version']} ({$d['license']})",
    $bundled,
));

$banner = "/*! {$package} {$version} (MIT, Copyright (c) Cbox)"
    .($notices !== '' ? " | bundles {$notices}" : '')
    ." | vendored by cboxdk/laravel-telemetry: composer browser-sdk -- <version> */\n";

$file = $banner.$bundle;
file_put_contents($root.'/resources/js/browser.js', $file);

file_put_contents($root.'/resources/js/browser-sdk.json', json_encode([
    'package' => $package,
    'version' => $version,
    'license' => $meta['license'] ?? 'MIT',
    'source' => [
        'tarball' => $meta['dist']['tarball'],
        'integrity' => $integrity,
        'file' => 'package/dist/telemetry.global.js',
        'git' => 'https://github.com/cboxdk/telemetry-browser',
        'tag' => 'v'.$version,
        'commit' => $commit,
    ],
    'sha256' => hash('sha256', $file),
    'bundles' => $bundled,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

$class = $root.'/src/Http/BrowserSdk.php';
file_put_contents($class, (string) preg_replace(
    "/public const VERSION = '[^']*';/",
    "public const VERSION = '{$version}';",
    (string) file_get_contents($class),
));

printf("Vendored %s@%s (%s, %.1f KB, commit %s).\n", $package, $version, $integrity, strlen($file) / 1024, $commit);
echo "Next: composer sbom, then a CHANGELOG entry.\n";
