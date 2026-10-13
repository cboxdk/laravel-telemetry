<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Tools\Request;

class HostileAiClockTool implements Tool
{
    public function description(): string
    {
        return 'What time is it';
    }

    public function handle(Request $request): string
    {
        return 'noon';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

class HostileAiAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'Tell the time.';
    }

    public function tools(): iterable
    {
        return [new HostileAiClockTool];
    }
}

it('runs, streams and embeds unharmed when the telemetry backend is gone', function (): void {
    $this->app->register(AiServiceProvider::class);

    // Same switch as NeverThrowsTest: every handler reaches the manager
    // through the container, so resolution that throws breaks them all.
    app()->forgetInstance(TelemetryManager::class);
    Telemetry::clearResolvedInstances();
    app()->bind(TelemetryManager::class, static function (): TelemetryManager {
        throw new RuntimeException('the telemetry backend is on fire');
    });

    $caught = [];
    FailSafe::handleExceptionsUsing(static function (Throwable $e) use (&$caught): void {
        $caught[] = $e;
    });

    try {
        HostileAiAgent::fake([new ToolCall('c1', 'HostileAiClockTool', []), 'It is noon.']);
        $answer = (new HostileAiAgent)->prompt('time?', provider: 'openai', model: 'gpt-test')->text;

        HostileAiAgent::fake([new ToolCall('c2', 'HostileAiClockTool', []), 'Still noon.']);
        $streamed = '';

        foreach ((new HostileAiAgent)->stream('time?', provider: 'openai', model: 'gpt-test') as $event) {
            if ($event instanceof TextDelta) {
                $streamed .= $event->delta;
            }
        }

        Embeddings::fake();
        $vectors = Embeddings::for(['x'])->dimensions(3)->generate('openai', 'embed-test')->embeddings;
    } finally {
        FailSafe::handleExceptionsUsing(null);
    }

    expect($answer)->toBe('It is noon.')
        ->and($streamed)->toBe('Still noon.')
        ->and($vectors)->toHaveCount(1)
        ->and($caught)->not->toBeEmpty('No laravel/ai event reached telemetry, so this proves nothing.');
});
