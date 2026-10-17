<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Tracing;

use Cbox\Telemetry\Support\Cast;

/**
 * Span conditions from config, for the tail detail policy's
 * `traces.details.keep_when` / `keep_spans`.
 *
 * A rule is a map of attribute → pattern, all of which must match; a list
 * of rules matches when any one does. `name` matches the span name. A
 * pattern may use `*` and `?`, and a list of patterns matches when any one
 * does:
 *
 *   [
 *       ['http.route' => ['checkout*', 'api/orders*']],
 *       ['gen_ai.operation.name' => 'invoke_agent', 'tenant.id' => '7'],
 *       ['name' => 'redis *'],
 *   ]
 *
 * Matching ignores case. An attribute the span does not carry never
 * matches, not even `*`.
 */
final class SpanRules
{
    /**
     * @param  list<array<string, list<string>>>  $rules
     */
    private function __construct(private readonly array $rules) {}

    public static function fromConfig(mixed $config): ?self
    {
        $rules = [];

        foreach (Cast::array($config) as $rule) {
            if (! is_array($rule) || $rule === []) {
                continue;
            }

            $conditions = [];

            foreach ($rule as $key => $patterns) {
                $patterns = is_array($patterns) ? $patterns : [$patterns];
                $patterns = array_values(array_filter(
                    array_map(static fn (mixed $p): string => is_scalar($p) ? (string) $p : '', $patterns),
                    static fn (string $p): bool => $p !== '',
                ));

                if (! is_string($key) || $key === '' || $patterns === []) {
                    // One unusable condition voids the rule: dropping it
                    // silently would widen the rule to more than was asked.
                    continue 2;
                }

                $conditions[$key] = $patterns;
            }

            $rules[] = $conditions;
        }

        return $rules === [] ? null : new self($rules);
    }

    public function matches(Span $span): bool
    {
        $attributes = $span->attributes();

        foreach ($this->rules as $rule) {
            foreach ($rule as $key => $patterns) {
                $value = $key === 'name' ? $span->name : ($attributes[$key] ?? null);

                if ($value === null || ! self::any($patterns, is_bool($value) ? ($value ? 'true' : 'false') : (string) $value)) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * @param  list<string>  $patterns
     */
    private static function any(array $patterns, string $value): bool
    {
        foreach ($patterns as $pattern) {
            if (strcasecmp($pattern, $value) === 0 || fnmatch($pattern, $value, FNM_NOESCAPE | FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }
}
