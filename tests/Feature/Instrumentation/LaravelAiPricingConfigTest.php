<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Tests\Feature\Instrumentation;

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Tests\TestCase;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\TextResponse;
use PHPUnit\Framework\Attributes\Test;

/**
 * The instrumentation reads its prices while the application boots, so the
 * config has to be in place before it does — hence a case of its own.
 */
final class LaravelAiPricingConfigTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), AiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('telemetry.instrument.ai_pricing', [
            'currency' => 'EUR',
            'models' => ['openai/gpt-cfg' => ['input' => 1.00, 'output' => 4.00]],
        ]);
    }

    #[Test]
    public function configured_prices_cost_the_model_calls(): void
    {
        $fake = Telemetry::fake();

        AnonymousAgent::fake([new TextResponse('ok', new TextUsage(500_000, 250_000), new Meta('openai', 'gpt-cfg'))]);

        (new AnonymousAgent('Be brief.', [], []))->prompt('hi', provider: 'openai', model: 'gpt-cfg');

        $cost = $fake->recordedSpans('chat gpt-cfg')[0]->attributes()['gen_ai.usage.cost'] ?? null;

        $this->assertEqualsWithDelta(1.5, $cost, 1e-12);
        $this->assertEqualsWithDelta(1.5, $fake->counterValue('gen_ai.client.cost', [
            'gen_ai.operation.name' => 'chat', 'gen_ai.provider.name' => 'openai', 'gen_ai.request.model' => 'gpt-cfg',
        ]), 1e-12);
    }
}
