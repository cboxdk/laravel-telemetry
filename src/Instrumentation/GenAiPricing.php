<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\Cast;
use Laravel\Ai\Responses\Data\TextUsage;

/**
 * Model prices the app configures itself (`instrument.ai_pricing`), turned
 * into a cost per model call for `gen_ai.usage.cost` and
 * `gen_ai.client.cost`.
 *
 * There is no built-in catalog on purpose: prices change, differ per
 * contract and region, and a stale catalog is a wrong number that looks
 * right. A model nobody priced gets no cost at all — never a guess.
 *
 * Prices are per 1M tokens. A key names a model (`gpt-4o`), optionally
 * prefixed by its provider (`openai/gpt-4o` — the laravel/ai driver or the
 * `gen_ai.provider.name` value), and may use `*` for dated snapshots
 * (`claude-sonnet-*`). An exact key wins over a pattern, and the longest
 * pattern over a shorter one.
 *
 * Token accounting follows laravel/ai: input tokens include cache reads and
 * writes, output tokens include reasoning, so each part is billed once.
 */
final class GenAiPricing
{
    /** @var array<string, array{input: float, output: float, cache_read: float, cache_write: float}> */
    private array $exact = [];

    /** @var array<string, array{input: float, output: float, cache_read: float, cache_write: float}> longest pattern first */
    private array $patterns = [];

    /** @var array<string, array{input: float, output: float, cache_read: float, cache_write: float}|null> */
    private array $resolved = [];

    /**
     * @param  array<array-key, mixed>  $models
     */
    public function __construct(array $models, public readonly string $currency = 'USD')
    {
        foreach ($models as $key => $price) {
            if (! is_string($key) || $key === '' || ! is_array($price)) {
                continue;
            }

            $input = self::price($price['input'] ?? null);
            $output = self::price($price['output'] ?? null);

            // A model needs at least one price; one with neither is a typo,
            // and costing it at zero would hide that.
            if ($input === null && $output === null) {
                continue;
            }

            $entry = [
                'input' => $input ?? 0.0,
                'output' => $output ?? 0.0,
                'cache_read' => self::price($price['cache_read'] ?? null) ?? $input ?? 0.0,
                'cache_write' => self::price($price['cache_write'] ?? null) ?? $input ?? 0.0,
            ];

            $key = strtolower($key);

            if (str_contains($key, '*')) {
                $this->patterns[$key] = $entry;
            } else {
                $this->exact[$key] = $entry;
            }
        }

        uksort($this->patterns, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    }

    /**
     * @param  array<array-key, mixed>  $config  the `instrument.ai_pricing` block
     */
    public static function fromConfig(array $config): ?self
    {
        $models = Cast::array($config['models'] ?? []);

        if ($models === []) {
            return null;
        }

        $currency = strtoupper(Cast::string($config['currency'] ?? 'USD', 'USD'));

        $pricing = new self($models, $currency === '' ? 'USD' : $currency);

        return $pricing->exact === [] && $pricing->patterns === [] ? null : $pricing;
    }

    /**
     * The cost of one model call, or null when its model is not priced.
     * The model the provider says it served is priced first (a dated
     * snapshot), then the one that was asked for.
     *
     * @param  list<string>  $providers  the names the provider goes by: its driver and its `gen_ai.provider.name`
     */
    public function textCost(array $providers, ?string $responseModel, string $model, TextUsage $usage): ?float
    {
        $price = $this->find($providers, $responseModel, $model);

        if ($price === null) {
            return null;
        }

        $cacheRead = max(0, $usage->cacheReadInputTokens ?? 0);
        $cacheWrite = max(0, $usage->cacheWriteInputTokens ?? 0);
        $uncached = max(0, $usage->inputTokens - $cacheRead - $cacheWrite);

        return ($uncached * $price['input']
            + $cacheRead * $price['cache_read']
            + $cacheWrite * $price['cache_write']
            + max(0, $usage->outputTokens) * $price['output']) / 1_000_000;
    }

    /**
     * @param  list<string>  $providers
     */
    public function embeddingsCost(array $providers, ?string $responseModel, string $model, int $inputTokens): ?float
    {
        $price = $this->find($providers, $responseModel, $model);

        return $price === null ? null : max(0, $inputTokens) * $price['input'] / 1_000_000;
    }

    /**
     * @param  list<string>  $providers
     * @return array{input: float, output: float, cache_read: float, cache_write: float}|null
     */
    private function find(array $providers, ?string $responseModel, string $model): ?array
    {
        foreach (array_unique(array_filter([$responseModel, $model])) as $candidate) {
            $price = $this->lookup($providers, $candidate);

            if ($price !== null) {
                return $price;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $providers
     * @return array{input: float, output: float, cache_read: float, cache_write: float}|null
     */
    private function lookup(array $providers, string $model): ?array
    {
        $model = strtolower($model);
        $cacheKey = implode(',', $providers).'|'.$model;

        if (array_key_exists($cacheKey, $this->resolved)) {
            return $this->resolved[$cacheKey];
        }

        $names = [];

        foreach ($providers as $provider) {
            $names[] = strtolower($provider).'/'.$model;
        }

        $names[] = $model;

        $price = null;

        foreach ($names as $name) {
            if (isset($this->exact[$name])) {
                $price = $this->exact[$name];

                break;
            }
        }

        if ($price === null) {
            foreach ($this->patterns as $pattern => $entry) {
                foreach ($names as $name) {
                    if (fnmatch($pattern, $name, FNM_NOESCAPE)) {
                        $price = $entry;

                        break 2;
                    }
                }
            }
        }

        // Bounded by the configured models times the models actually used.
        if (count($this->resolved) < 256) {
            $this->resolved[$cacheKey] = $price;
        }

        return $price;
    }

    private static function price(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }

        $price = (float) $value;

        return $price >= 0 && is_finite($price) ? $price : null;
    }
}
