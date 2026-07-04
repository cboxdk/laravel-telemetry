<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Resolves minified browser stack frames back to the original source using
 * uploaded source maps (v3). The frontend SDK reports minified frames whose
 * file names/line numbers shift every deploy; given the release's source
 * maps, this turns them into original source/line/column/name — the piece
 * that makes browser error grouping and detail as good as the backend's.
 *
 * A self-contained VLQ decoder — no ext, no library.
 */
final class Symbolicator
{
    private const B64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    /**
     * Per-release map cache within one request.
     *
     * @var array<string, array<string, mixed>|null>
     */
    private array $cache = [];

    public function __construct(
        private readonly Filesystem $disk,
        private readonly string $prefix = 'telemetry/sourcemaps',
    ) {}

    /**
     * Symbolicate a raw browser stack string into original frames.
     *
     * @return list<array{function: ?string, file: string, line: int, column: int, original: bool}>
     */
    public function symbolicateStack(string $release, string $stack): array
    {
        return array_map(fn (array $f) => $this->resolveFrame($release, $f), self::parseStack($stack));
    }

    /**
     * Parse a browser stack string (Chrome "at fn (url:line:col)" and
     * Firefox/Safari "fn@url:line:col") into structured frames.
     *
     * @return list<array{function: ?string, file: string, line: int, column: int}>
     */
    public static function parseStack(string $stack): array
    {
        $frames = [];

        foreach (preg_split('/\r?\n/', $stack) ?: [] as $line) {
            if (preg_match('/(?:at\s+(?<fn1>.+?)\s+\()?(?<url>https?:\/\/[^\s()]+?|\/[^\s()]+?):(?<line>\d+):(?<col>\d+)\)?\s*$/', trim($line), $m)) {
                $fn = $m['fn1'] !== '' ? $m['fn1'] : null;
                if ($fn === null && preg_match('/^(?<fn2>.+?)@/', trim($line), $fm)) {
                    $fn = $fm['fn2'];
                }
                $frames[] = [
                    'function' => $fn,
                    'file' => $m['url'],
                    'line' => (int) $m['line'],
                    'column' => (int) $m['col'],
                ];
            }
        }

        return $frames;
    }

    /**
     * @param  array{function: ?string, file: string, line: int, column: int}  $frame
     * @return array{function: ?string, file: string, line: int, column: int, original: bool}
     */
    private function resolveFrame(string $release, array $frame): array
    {
        $map = $this->map($release, $frame['file']);

        // Browser columns are 1-based; source maps are 0-based.
        $resolved = $map !== null ? $this->resolve($map, $frame['line'], max(0, $frame['column'] - 1)) : null;

        if ($resolved === null) {
            return [...$frame, 'original' => false];
        }

        return [
            'function' => $resolved['name'] ?? $frame['function'],
            'file' => $resolved['source'] ?? $frame['file'],
            'line' => $resolved['line'],
            'column' => $resolved['column'],
            'original' => true,
        ];
    }

    /**
     * The source-map document for a minified file URL, keyed by basename.
     *
     * @return array<string, mixed>|null
     */
    private function map(string $release, string $fileUrl): ?array
    {
        $base = basename((string) (parse_url($fileUrl, PHP_URL_PATH) ?: $fileUrl));
        $key = $release.'/'.$base;

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return FailSafe::guard(function () use ($release, $base, $key): ?array {
            $path = "{$this->prefix}/{$release}/{$base}.map";

            if (! $this->disk->exists($path)) {
                return $this->cache[$key] = null;
            }

            $decoded = json_decode((string) $this->disk->get($path), true);

            return $this->cache[$key] = is_array($decoded) ? $decoded : null;
        });
    }

    /**
     * Resolve a generated (1-based line, 0-based column) to its original.
     *
     * @param  array<string, mixed>  $map
     * @return array{source: ?string, line: int, column: int, name: ?string}|null
     */
    public function resolve(array $map, int $line, int $column): ?array
    {
        $mappings = is_string($map['mappings'] ?? null) ? $map['mappings'] : '';
        $sources = is_array($map['sources'] ?? null) ? $map['sources'] : [];
        $names = is_array($map['names'] ?? null) ? $map['names'] : [];
        $lines = explode(';', $mappings);

        // Source index / line / column / name index are cumulative across
        // the WHOLE file; generated column resets per line. So we must walk
        // every line up to the target to accumulate correctly.
        $srcIdx = 0;
        $srcLine = 0;
        $srcCol = 0;
        $nameIdx = 0;

        foreach ($lines as $l => $encoded) {
            $genCol = 0;
            $best = null;

            foreach (array_filter(explode(',', $encoded), static fn ($s) => $s !== '') as $segment) {
                $fields = $this->decodeSegment($segment);
                $genCol += $fields[0] ?? 0;
                if (isset($fields[1])) {
                    $srcIdx += $fields[1];
                }
                if (isset($fields[2])) {
                    $srcLine += $fields[2];
                }
                if (isset($fields[3])) {
                    $srcCol += $fields[3];
                }
                if (isset($fields[4])) {
                    $nameIdx += $fields[4];
                }

                if ($l === $line - 1 && $genCol <= $column) {
                    $best = [
                        'source' => is_string($sources[$srcIdx] ?? null) ? $sources[$srcIdx] : null,
                        'line' => $srcLine + 1,
                        'column' => $srcCol,
                        'name' => isset($fields[4]) && is_string($names[$nameIdx] ?? null) ? $names[$nameIdx] : null,
                    ];
                }
            }

            if ($l === $line - 1) {
                return $best;
            }
        }

        return null;
    }

    /**
     * Decode one VLQ segment into its integer fields.
     *
     * @return list<int>
     */
    private function decodeSegment(string $segment): array
    {
        $result = [];
        $value = 0;
        $shift = 0;

        for ($i = 0, $n = strlen($segment); $i < $n; $i++) {
            $digit = strpos(self::B64, $segment[$i]);
            if ($digit === false) {
                break;
            }

            $continuation = $digit & 32;
            $digit &= 31;
            $value += $digit << $shift;

            if ($continuation) {
                $shift += 5;
            } else {
                $result[] = ($value & 1) ? -($value >> 1) : ($value >> 1);
                $value = 0;
                $shift = 0;
            }
        }

        return $result;
    }
}
