<?php

declare(strict_types=1);

use Cbox\Telemetry\Instrumentation\GenAiPricing;
use Laravel\Ai\Responses\Data\TextUsage;

function aiCost(GenAiPricing $pricing, string $model, ?string $responseModel = null, array $providers = ['openai']): ?float
{
    return $pricing->textCost($providers, $responseModel, $model, new TextUsage(1_000_000, 0));
}

it('prefers an exact key over a pattern, and a longer pattern over a shorter one', function () {
    $pricing = new GenAiPricing([
        'gpt-*' => ['input' => 1],
        'gpt-4o*' => ['input' => 2],
        'gpt-4o-mini' => ['input' => 3],
    ]);

    expect(aiCost($pricing, 'gpt-4o-mini'))->toBe(3.0)
        ->and(aiCost($pricing, 'gpt-4o-2024-08-06'))->toBe(2.0)
        ->and(aiCost($pricing, 'gpt-5'))->toBe(1.0)
        ->and(aiCost($pricing, 'claude-sonnet-5'))->toBeNull();
});

it('matches a provider prefix by driver or by gen_ai.provider.name, case-insensitively', function () {
    $pricing = new GenAiPricing(['GCP.Gemini/gemini-pro' => ['input' => 4], 'openai/gpt-x' => ['input' => 5]]);

    expect(aiCost($pricing, 'gemini-pro', providers: ['gemini', 'gcp.gemini']))->toBe(4.0)
        ->and(aiCost($pricing, 'gpt-x', providers: ['openai']))->toBe(5.0)
        // Another provider's model of the same name is not this price.
        ->and(aiCost($pricing, 'gpt-x', providers: ['azure', 'azure.ai.openai']))->toBeNull();
});

it('prices the served snapshot before the model that was asked for', function () {
    $pricing = new GenAiPricing(['gpt-x' => ['input' => 1], 'gpt-x-2026-01' => ['input' => 9]]);

    expect(aiCost($pricing, 'gpt-x', 'gpt-x-2026-01'))->toBe(9.0)
        ->and(aiCost($pricing, 'gpt-x', 'gpt-x-unknown'))->toBe(1.0);
});

it('bills each part of the prompt once, cache prices falling back to the input price', function () {
    $pricing = new GenAiPricing(['m' => ['input' => 3, 'output' => 15, 'cache_write' => 3.75]]);

    // 1000 in = 600 uncached + 300 cache reads + 100 cache writes; reads fall back to input.
    $cost = $pricing->textCost(['x'], null, 'm', new TextUsage(1000, 200, cacheReadInputTokens: 300, cacheWriteInputTokens: 100));

    expect($cost)->toEqualWithDelta((600 * 3 + 300 * 3 + 100 * 3.75 + 200 * 15) / 1_000_000, 1e-15);
});

it('skips entries with no usable price instead of costing them at zero', function () {
    $pricing = new GenAiPricing([
        'typo' => ['inptu' => 3],
        'negative' => ['input' => -1],
        'text' => ['input' => 'cheap'],
        'numeric' => ['input' => '0.5'],
        0 => ['input' => 1],
    ]);

    expect(aiCost($pricing, 'typo'))->toBeNull()
        ->and(aiCost($pricing, 'negative'))->toBeNull()
        ->and(aiCost($pricing, 'text'))->toBeNull()
        ->and(aiCost($pricing, 'numeric'))->toBe(0.5);
});

it('builds nothing from an empty or unusable config', function () {
    expect(GenAiPricing::fromConfig([]))->toBeNull()
        ->and(GenAiPricing::fromConfig(['models' => []]))->toBeNull()
        ->and(GenAiPricing::fromConfig(['models' => ['m' => ['nope' => 1]]]))->toBeNull()
        ->and(GenAiPricing::fromConfig(['currency' => 'eur', 'models' => ['m' => ['input' => 1]]])?->currency)->toBe('EUR');
});
