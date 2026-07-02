<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

/**
 * Zero-config deployment marker: when `service.deployment` is not set,
 * the current git commit identifies the deploy. Two small file reads at
 * boot — no `exec()`, works in containers that ship .git, silently
 * absent when they don't.
 */
final class GitVersion
{
    public static function detect(string $basePath): ?string
    {
        return FailSafe::guard(function () use ($basePath): ?string {
            $head = $basePath.'/.git/HEAD';

            if (! is_file($head) || ! is_readable($head)) {
                return null;
            }

            $contents = trim((string) file_get_contents($head));

            // Detached HEAD: the file IS the sha.
            if (preg_match('/^[0-9a-f]{40}$/', $contents) === 1) {
                return substr($contents, 0, 12);
            }

            if (! str_starts_with($contents, 'ref: ')) {
                return null;
            }

            $ref = $basePath.'/.git/'.substr($contents, 5);

            if (is_file($ref) && is_readable($ref)) {
                $sha = trim((string) file_get_contents($ref));

                return preg_match('/^[0-9a-f]{40}$/', $sha) === 1 ? substr($sha, 0, 12) : null;
            }

            // Packed refs fallback.
            $packed = $basePath.'/.git/packed-refs';

            if (is_file($packed) && is_readable($packed)) {
                foreach (explode("\n", (string) file_get_contents($packed)) as $line) {
                    if (str_ends_with($line, ' '.substr($contents, 5)) && preg_match('/^([0-9a-f]{40}) /', $line, $m) === 1) {
                        return substr($m[1], 0, 12);
                    }
                }
            }

            return null;
        });
    }
}
