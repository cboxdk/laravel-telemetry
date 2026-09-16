<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Native;

use Cbox\Telemetry\Support\Cast;

/**
 * What one finished unit of work measured: its duration, the native
 * operation aggregates, the runtime counters, and — only when it was
 * asked for — the CPU profile.
 *
 * Nanoseconds become milliseconds here, because that is the unit every
 * duration this package already reports is in.
 */
final readonly class NativeResult
{
    /**
     * @param  array<string, array{count: int, total_ms: float, max_ms: float}>  $operations
     * @param  array<string, int|bool>  $counters
     */
    private function __construct(
        public float $durationMs,
        public string $unit,
        public bool $sampled,
        public bool $profiling,
        public bool $automatic,
        public array $operations,
        public array $counters,
        public ?NativeProfile $profile,
    ) {}

    /**
     * Null for the empty array the extension returns when the handle is
     * not the open one — a unit someone else already closed, or one that
     * a nested `begin()` abandoned. That is not a failure, and it must
     * not be reported as a unit that took 0 ms.
     */
    public static function fromArray(mixed $raw, int $topFunctions = 20, int $maxStackNodes = 2048): ?self
    {
        $result = Cast::stringKeyedArray($raw);

        if ($result === [] || ! isset($result['duration_ns'])) {
            return null;
        }

        $counters = self::counters($result['counters'] ?? null);

        return new self(
            durationMs: Cast::int($result['duration_ns']) / 1_000_000,
            unit: Cast::string($result['unit'] ?? null, 'other'),
            sampled: Cast::bool($result['sampled'] ?? null),
            profiling: Cast::bool($result['profiling'] ?? null),
            automatic: Cast::bool($result['automatic'] ?? null),
            operations: self::operations($result['operations'] ?? null),
            counters: $counters,
            profile: NativeProfile::fromArray($result['profile'] ?? null, $counters, $topFunctions, $maxStackNodes),
        );
    }

    public function counter(string $name): int
    {
        $value = $this->counters[$name] ?? 0;

        return is_int($value) ? $value : (int) $value;
    }

    /**
     * @return array<string, array{count: int, total_ms: float, max_ms: float}>
     */
    private static function operations(mixed $raw): array
    {
        $operations = [];

        foreach (Cast::stringKeyedArray($raw) as $name => $aggregate) {
            $aggregate = Cast::stringKeyedArray($aggregate);
            $count = Cast::int($aggregate['count'] ?? null);

            if ($count <= 0) {
                continue;
            }

            $operations[$name] = [
                'count' => $count,
                'total_ms' => round(Cast::int($aggregate['total_ns'] ?? null) / 1_000_000, 3),
                'max_ms' => round(Cast::int($aggregate['max_ns'] ?? null) / 1_000_000, 3),
            ];
        }

        return $operations;
    }

    /**
     * @return array<string, int|bool>
     */
    private static function counters(mixed $raw): array
    {
        $counters = [];

        foreach (Cast::stringKeyedArray($raw) as $name => $value) {
            if (is_int($value) || is_bool($value)) {
                $counters[$name] = $value;
            }
        }

        return $counters;
    }
}
