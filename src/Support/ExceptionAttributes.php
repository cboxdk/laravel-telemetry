<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

use Throwable;

/**
 * Builds the OTel-semconv exception attributes plus a Sentry-style
 * fingerprint, so downstream tools (Grafana, telemetry-ui) can group
 * identical failures into issues instead of merging everything by class.
 *
 * Framework-agnostic (no Laravel facades) — the base path is passed in so
 * file paths can be made project-relative.
 */
final class ExceptionAttributes
{
    /** stacktrace / source can bloat OTLP batches — cap defensively. */
    private const MAX_STACKTRACE = 12000;

    private const MAX_SOURCE = 4000;

    /**
     * @return array<string, scalar>
     */
    public static function from(Throwable $e, string $basePath = '', bool $withSource = false): array
    {
        $attributes = [
            'exception.type' => $e::class,
            'exception.message' => $e->getMessage(),
            'exception.file' => self::relative($e->getFile(), $basePath),
            'exception.line' => $e->getLine(),
            'exception.stacktrace' => self::cap($e->getTraceAsString(), self::MAX_STACKTRACE),
            'exception.group' => self::fingerprint($e),
        ];

        if ($withSource && ($source = self::source($e->getFile(), $e->getLine())) !== null) {
            $attributes['exception.source'] = $source;
        }

        return array_filter($attributes, static fn ($value): bool => $value !== '');
    }

    /**
     * Sentry-style fingerprint: exception class + the top in-app frame
     * (basename:line, ignoring vendor/) — machine-independent, so the same
     * error from the same site groups together across hosts and deploys.
     */
    public static function fingerprint(Throwable $e): string
    {
        // The stable identifier of an error is WHERE it is raised. Prefer
        // the throw site itself when it's app code (identical across every
        // occurrence of the same throw); fall back to the top in-app frame
        // when the throw is inside a framework/vendor.
        $site = self::isAppFile($e->getFile())
            ? basename($e->getFile()).':'.$e->getLine()
            : (self::topAppFrame($e) ?? basename($e->getFile()).':'.$e->getLine());

        return substr(hash('sha256', $e::class.'@'.$site), 0, 12);
    }

    private static function topAppFrame(Throwable $e): ?string
    {
        foreach ($e->getTrace() as $frame) {
            $file = $frame['file'] ?? null;

            if (is_string($file) && self::isAppFile($file)) {
                return basename($file).':'.(is_int($frame['line'] ?? null) ? $frame['line'] : 0);
            }
        }

        return null;
    }

    private static function isAppFile(string $file): bool
    {
        return $file !== '' && ! str_contains($file, '/vendor/');
    }

    private static function relative(string $file, string $basePath): string
    {
        if ($basePath !== '' && str_starts_with($file, $basePath)) {
            return ltrim(substr($file, strlen($basePath)), '/');
        }

        return $file;
    }

    /**
     * The few source lines around the throw site — the "feels like Sentry"
     * bit. Opt-in (it reads the file). Never throws.
     */
    private static function source(string $file, int $line, int $pad = 6): ?string
    {
        if ($line < 1 || ! is_file($file) || ! is_readable($file)) {
            return null;
        }

        $lines = FailSafe::guard(static fn () => file($file, FILE_IGNORE_NEW_LINES));

        if (! is_array($lines) || $lines === []) {
            return null;
        }

        $start = max(0, $line - 1 - $pad);
        $end = min(count($lines) - 1, $line - 1 + $pad);
        $out = [];

        for ($i = $start; $i <= $end; $i++) {
            $out[] = sprintf('%s%d| %s', $i + 1 === $line ? '> ' : '  ', $i + 1, (string) $lines[$i]);
        }

        return self::cap(implode("\n", $out), self::MAX_SOURCE);
    }

    private static function cap(string $value, int $max): string
    {
        return strlen($value) > $max ? substr($value, 0, $max)."\n… (truncated)" : $value;
    }
}
