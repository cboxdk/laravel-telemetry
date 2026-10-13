<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

/**
 * The line of application code that caused an operation.
 *
 * A query span says WHAT ran; the question a developer — or an agent —
 * asks next is "where is that in my code". The answer is the first frame
 * on the stack that is neither vendor code nor this package: the
 * `$order->items` in a Blade loop, not Eloquent's `getResults()`.
 *
 * `code.file.path` is relative to the application base path, so the same
 * line groups together across hosts and deploys (the same reasoning as
 * the exception fingerprint).
 *
 * Not free: a backtrace costs a few microseconds, which is why callers
 * ask only for the operations worth pointing at (slow or flagged queries)
 * unless configured otherwise. Arguments are never captured.
 */
final class SourceLocator
{
    /** Frames walked before giving up — deep enough for Eloquent → Blade. */
    private const DEPTH = 60;

    private static ?string $packageRoot = null;

    /**
     * @return array{'code.file.path': string, 'code.line.number': int, 'code.function.name'?: string}|null
     */
    public static function locate(string $basePath = ''): ?array
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::DEPTH);

        foreach ($frames as $index => $frame) {
            $file = $frame['file'] ?? null;

            if (! is_string($file) || ! self::isAppFile($file)) {
                continue;
            }

            $location = [
                'code.file.path' => self::relative($file, $basePath),
                'code.line.number' => $frame['line'] ?? 0,
            ];

            // The function the line sits IN is the next frame's — frame N
            // is the call made from file:line, frame N+1 is its caller.
            $caller = $frames[$index + 1] ?? null;

            if ($caller !== null) {
                $location['code.function.name'] = isset($caller['class'])
                    ? $caller['class'].'::'.$caller['function']
                    : $caller['function'];
            }

            return $location;
        }

        return null;
    }

    private static function isAppFile(string $file): bool
    {
        self::$packageRoot ??= dirname(__DIR__);

        return ! str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)
            && ! str_starts_with($file, self::$packageRoot.DIRECTORY_SEPARATOR);
    }

    private static function relative(string $file, string $basePath): string
    {
        if ($basePath !== '' && str_starts_with($file, rtrim($basePath, '/').'/')) {
            return substr($file, strlen(rtrim($basePath, '/')) + 1);
        }

        return $file;
    }
}
