<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Instrumentation\GenAiContent;
use Cbox\Telemetry\Instrumentation\GenAiPricing;
use Cbox\Telemetry\Instrumentation\LaravelAiInstrumentation;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\SpanStatus;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Ai\Ai;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Events;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Tools\Request;

class AiTestWeatherTool implements Tool
{
    public static bool $fail = false;

    public function description(): string
    {
        return 'Current weather for a city';
    }

    public function handle(Request $request): string
    {
        if (self::$fail) {
            throw new RuntimeException('weather service down');
        }

        return 'Sunny, 21C in '.$request['city'];
    }

    public function schema(JsonSchema $schema): array
    {
        return ['city' => $schema->string()->required()];
    }
}

class AiTestAssistant implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful weather assistant.';
    }

    public function tools(): iterable
    {
        return [new AiTestWeatherTool];
    }
}

class AiTestResearcher implements Agent, CanActAsTool
{
    use Promptable;

    public function instructions(): string
    {
        return 'You research things.';
    }

    public function name(): string
    {
        return 'researcher';
    }

    public function description(): string
    {
        return 'Looks things up';
    }
}

class AiTestOrchestrator implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'Delegate research.';
    }

    public function tools(): iterable
    {
        return [new AiTestResearcher];
    }
}

beforeEach(function () {
    $this->app->register(AiServiceProvider::class);
    AiTestWeatherTool::$fail = false;

    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);
});

/**
 * @return Collection<int, Span>
 */
function aiSpans(CollectingExporter $collector): Collection
{
    Telemetry::flush();

    return collect($collector->batches())->flatMap(fn ($batch) => $batch->spans)->values();
}

function aiSpan(Collection $spans, string $name, int $nth = 0): Span
{
    $span = $spans->filter(fn (Span $span) => $span->name === $name)->values()->get($nth);

    expect($span)->not->toBeNull("no span named [{$name}] among: ".$spans->pluck('name')->implode(', '));

    return $span;
}

/**
 * Swap the boot-time instrumentation for one built the way the provider
 * builds it with instrument.ai_content on.
 */
function rearmAi(?GenAiContent $content, ?GenAiPricing $pricing = null): void
{
    $events = app(Dispatcher::class);

    foreach ([
        Events\PromptingAgent::class, Events\StreamingAgent::class, Events\AgentPrompted::class,
        Events\AgentStreamed::class, Events\AgentFailed::class, Events\AgentFailedOver::class,
        Events\StartingStep::class, Events\StepCompleted::class, Events\StepFailed::class,
        Events\InvokingTool::class, Events\ToolInvoked::class, Events\ToolFailed::class,
        Events\GeneratingEmbeddings::class, Events\EmbeddingsGenerated::class, Events\ProviderFailedOver::class,
    ] as $event) {
        $events->forget($event);
    }

    (new LaravelAiInstrumentation(app(), $content, $pricing))->register($events);
}

function fakeWeatherRun(): void
{
    AiTestAssistant::fake([
        new ToolCall('call_1', 'AiTestWeatherTool', ['city' => 'Aarhus']),
        new TextResponse('It is sunny in Aarhus.', new TextUsage(120, 14, cacheReadInputTokens: 100), new Meta('openai', 'gpt-test-2026-01')),
    ]);
}

it('traces an agent run as invoke_agent with chat and execute_tool children', function () {
    fakeWeatherRun();

    Telemetry::span('request', fn () => (new AiTestAssistant)->prompt('Weather in Aarhus?', provider: 'openai', model: 'gpt-test'));

    $spans = aiSpans($this->collector);
    $request = aiSpan($spans, 'request');
    $agent = aiSpan($spans, 'invoke_agent AiTestAssistant');
    $firstCall = aiSpan($spans, 'chat gpt-test', 0);
    $tool = aiSpan($spans, 'execute_tool AiTestWeatherTool');
    $secondCall = aiSpan($spans, 'chat gpt-test', 1);

    // The run joins the trace it ran in; its model calls and tools are its
    // children, in the order they happened.
    expect($agent->parentSpanId)->toBe($request->spanId)
        ->and($agent->traceId)->toBe($request->traceId)
        ->and($firstCall->parentSpanId)->toBe($agent->spanId)
        ->and($tool->parentSpanId)->toBe($agent->spanId)
        ->and($secondCall->parentSpanId)->toBe($agent->spanId)
        ->and($tool->startUnixNano())->toBeGreaterThanOrEqual($firstCall->endUnixNano())
        ->and($secondCall->startUnixNano())->toBeGreaterThanOrEqual($tool->endUnixNano());

    expect($agent->kind)->toBe(SpanKind::Internal)
        ->and($agent->status())->toBe(SpanStatus::Ok)
        ->and($agent->attributes())->toMatchArray([
            'gen_ai.operation.name' => 'invoke_agent',
            'gen_ai.provider.name' => 'openai',
            'gen_ai.agent.name' => 'AiTestAssistant',
            'gen_ai.request.model' => 'gpt-test',
            'gen_ai.response.model' => 'gpt-test-2026-01',
            'gen_ai.usage.input_tokens' => 120,
            'gen_ai.usage.output_tokens' => 14,
            'gen_ai.response.finish_reasons' => '["stop"]',
        ])
        // Cache breakdowns only on the model call that incurred them.
        ->and($agent->attributes())->not->toHaveKey('gen_ai.usage.cache_read.input_tokens');

    expect($firstCall->kind)->toBe(SpanKind::Client)
        ->and($firstCall->attributes())->toMatchArray([
            'gen_ai.operation.name' => 'chat',
            'gen_ai.provider.name' => 'openai',
            'gen_ai.request.model' => 'gpt-test',
            'gen_ai.response.finish_reasons' => '["tool_calls"]',
        ])
        // The fake reported no usage for the tool-call step: nothing invented.
        ->and($firstCall->attributes())->not->toHaveKey('gen_ai.usage.input_tokens');

    expect($secondCall->attributes())->toMatchArray([
        'gen_ai.response.model' => 'gpt-test-2026-01',
        'gen_ai.usage.input_tokens' => 120,
        'gen_ai.usage.output_tokens' => 14,
        'gen_ai.usage.cache_read.input_tokens' => 100,
        'gen_ai.response.finish_reasons' => '["stop"]',
    ]);

    expect($tool->kind)->toBe(SpanKind::Internal)
        ->and($tool->status())->toBe(SpanStatus::Ok)
        ->and($tool->attributes())->toMatchArray([
            'gen_ai.operation.name' => 'execute_tool',
            'gen_ai.tool.name' => 'AiTestWeatherTool',
            'gen_ai.tool.type' => 'function',
            'gen_ai.tool.description' => 'Current weather for a city',
        ]);
});

it('records no prompt, completion or tool content by default', function () {
    fakeWeatherRun();

    (new AiTestAssistant)->prompt('my secret question', provider: 'openai', model: 'gpt-test');

    $spans = aiSpans($this->collector);

    $attributes = $spans->flatMap(fn (Span $span) => array_keys($span->attributes()));

    expect($attributes)->not->toContain('gen_ai.input.messages')
        ->and($attributes)->not->toContain('gen_ai.output.messages')
        ->and($attributes)->not->toContain('gen_ai.system_instructions')
        ->and($attributes)->not->toContain('gen_ai.tool.call.arguments')
        ->and($attributes)->not->toContain('gen_ai.tool.call.result')
        ->and(json_encode($spans->map(fn (Span $span) => $span->attributes())))->not->toContain('my secret question');
});

it('records content in the OTel message shape when opted in', function () {
    rearmAi(new GenAiContent(2048));
    fakeWeatherRun();

    (new AiTestAssistant)->prompt('Weather in Aarhus?', provider: 'openai', model: 'gpt-test');

    $spans = aiSpans($this->collector);
    $agent = aiSpan($spans, 'invoke_agent AiTestAssistant')->attributes();
    $firstCall = aiSpan($spans, 'chat gpt-test', 0)->attributes();
    $secondCall = aiSpan($spans, 'chat gpt-test', 1)->attributes();
    $tool = aiSpan($spans, 'execute_tool AiTestWeatherTool')->attributes();

    expect(json_decode($agent['gen_ai.system_instructions'], true))
        ->toBe([['type' => 'text', 'content' => 'You are a helpful weather assistant.']])
        ->and(json_decode($agent['gen_ai.input.messages'], true))
        ->toBe([['role' => 'user', 'parts' => [['type' => 'text', 'content' => 'Weather in Aarhus?']]]])
        ->and(json_decode($agent['gen_ai.output.messages'], true))
        ->toBe([['role' => 'assistant', 'parts' => [['type' => 'text', 'content' => 'It is sunny in Aarhus.']], 'finish_reason' => 'stop']]);

    expect(json_decode($firstCall['gen_ai.output.messages'], true))->toBe([[
        'role' => 'assistant',
        'parts' => [['type' => 'tool_call', 'id' => 'call_1', 'name' => 'AiTestWeatherTool', 'arguments' => ['city' => 'Aarhus']]],
        'finish_reason' => 'tool_calls',
    ]]);

    // The second call sends the whole history: prompt, tool call, tool result.
    expect(json_decode($secondCall['gen_ai.input.messages'], true))->toBe([
        ['role' => 'user', 'parts' => [['type' => 'text', 'content' => 'Weather in Aarhus?']]],
        ['role' => 'assistant', 'parts' => [['type' => 'tool_call', 'id' => 'call_1', 'name' => 'AiTestWeatherTool', 'arguments' => ['city' => 'Aarhus']]]],
        ['role' => 'tool', 'parts' => [['type' => 'tool_call_response', 'id' => 'call_1', 'response' => 'Sunny, 21C in Aarhus']]],
    ]);

    expect(json_decode($tool['gen_ai.tool.call.arguments'], true))->toBe(['city' => 'Aarhus'])
        ->and($tool['gen_ai.tool.call.result'])->toBe('Sunny, 21C in Aarhus');
});

it('caps captured content to the byte budget and keeps it valid JSON and UTF-8', function () {
    rearmAi(new GenAiContent(256));

    AiTestAssistant::fake([new TextResponse(str_repeat('ø', 1000), new TextUsage(1, 1), new Meta('openai', 'gpt-test'))]);

    (new AiTestAssistant)->prompt(str_repeat('æ', 1000), provider: 'openai', model: 'gpt-test');

    $agent = aiSpan(aiSpans($this->collector), 'invoke_agent AiTestAssistant')->attributes();

    $input = json_decode($agent['gen_ai.input.messages'], true, flags: JSON_THROW_ON_ERROR);
    $output = json_decode($agent['gen_ai.output.messages'], true, flags: JSON_THROW_ON_ERROR);

    $prompt = $input[0]['parts'][0]['content'];
    $completion = $output[0]['parts'][0]['content'];

    expect(strlen($prompt))->toBeLessThanOrEqual(256)
        ->and(mb_check_encoding($prompt, 'UTF-8'))->toBeTrue()
        ->and($prompt)->toEndWith(GenAiContent::TRUNCATED)
        ->and(strlen($completion))->toBeLessThanOrEqual(256)
        ->and(mb_check_encoding($completion, 'UTF-8'))->toBeTrue();
});

it('shares the budget across a long history instead of growing with it', function () {
    $content = new GenAiContent(1024);

    $messages = [];

    for ($i = 0; $i < 50; $i++) {
        $messages[] = new UserMessage(str_repeat('x', 500));
    }

    expect(strlen($content->messages($messages)))->toBeLessThan(50 * (64 + 80));
});

it('records the operation duration and token usage metrics per model call', function () {
    fakeWeatherRun();

    (new AiTestAssistant)->prompt('Weather?', provider: 'openai', model: 'gpt-test');

    $fake = Telemetry::fake();
    fakeWeatherRun();
    (new AiTestAssistant)->prompt('Weather?', provider: 'openai', model: 'gpt-test');

    $labels = ['gen_ai.operation.name' => 'chat', 'gen_ai.provider.name' => 'openai', 'gen_ai.request.model' => 'gpt-test'];

    // Two model calls, one duration each — the agent run itself is not an
    // inference and is not counted again.
    expect($fake->histogramCount('gen_ai.client.operation.duration', $labels))->toBe(2)
        ->and($fake->histogramCount('gen_ai.client.token.usage', [...$labels, 'gen_ai.token.type' => 'input']))->toBe(1)
        ->and($fake->histogramSum('gen_ai.client.token.usage', [...$labels, 'gen_ai.token.type' => 'input']))->toBe(120.0)
        ->and($fake->histogramSum('gen_ai.client.token.usage', [...$labels, 'gen_ai.token.type' => 'output']))->toBe(14.0);

    $fake->assertMetricLabelValues('gen_ai.client.operation.duration', 'gen_ai.operation.name', ['chat']);
});

it('fails the chat and agent spans when the model call throws, and still throws to the app', function () {
    AiTestAssistant::fake(fn () => throw new RuntimeException('provider exploded'));

    expect(fn () => Telemetry::span('request', fn () => (new AiTestAssistant)->prompt('hi', provider: 'openai', model: 'gpt-test')))
        ->toThrow(RuntimeException::class, 'provider exploded');

    $spans = aiSpans($this->collector);
    $agent = aiSpan($spans, 'invoke_agent AiTestAssistant');
    $call = aiSpan($spans, 'chat gpt-test');

    expect($call->status())->toBe(SpanStatus::Error)
        ->and($call->attributes()['error.type'])->toBe(RuntimeException::class)
        ->and($call->attributes()['gen_ai.response.finish_reasons'])->toBe('["error"]')
        ->and($call->parentSpanId)->toBe($agent->spanId)
        ->and($agent->status())->toBe(SpanStatus::Error)
        ->and($agent->attributes()['error.type'])->toBe(RuntimeException::class);

    // Nothing left open on the context stack.
    expect(Telemetry::currentSpan())->toBeNull();
});

it('labels a failed model call duration with error.type and records no tokens for it', function () {
    $fake = Telemetry::fake();
    AiTestAssistant::fake(fn () => throw new RuntimeException('nope'));

    try {
        (new AiTestAssistant)->prompt('hi', provider: 'openai', model: 'gpt-test');
    } catch (RuntimeException) {
    }

    $fake->assertHistogramRecorded('gen_ai.client.operation.duration', [
        'gen_ai.operation.name' => 'chat',
        'gen_ai.provider.name' => 'openai',
        'gen_ai.request.model' => 'gpt-test',
        'error.type' => RuntimeException::class,
    ]);
    $fake->assertHistogramNotRecorded('gen_ai.client.token.usage');
});

it('fails the execute_tool span when the tool throws', function () {
    AiTestWeatherTool::$fail = true;
    fakeWeatherRun();

    expect(fn () => (new AiTestAssistant)->prompt('Weather?', provider: 'openai', model: 'gpt-test'))
        ->toThrow(RuntimeException::class, 'weather service down');

    $spans = aiSpans($this->collector);
    $tool = aiSpan($spans, 'execute_tool AiTestWeatherTool');

    expect($tool->status())->toBe(SpanStatus::Error)
        ->and($tool->attributes()['error.type'])->toBe(RuntimeException::class)
        ->and(aiSpan($spans, 'invoke_agent AiTestAssistant')->status())->toBe(SpanStatus::Error)
        ->and(Telemetry::currentSpan())->toBeNull();
});

it('gives each failover attempt its own invoke_agent span', function () {
    $calls = 0;

    AiTestAssistant::fake(function () use (&$calls) {
        if ($calls++ === 0) {
            throw RateLimitedException::forProvider('openai');
        }

        return new TextResponse('ok', new TextUsage(3, 1), new Meta('anthropic', 'claude-test'));
    });

    (new AiTestAssistant)->prompt('hi', provider: ['openai' => 'gpt-test', 'anthropic' => 'claude-test']);

    $spans = aiSpans($this->collector);
    $first = aiSpan($spans, 'invoke_agent AiTestAssistant', 0);
    $second = aiSpan($spans, 'invoke_agent AiTestAssistant', 1);

    expect($first->status())->toBe(SpanStatus::Error)
        ->and($first->attributes()['gen_ai.provider.name'])->toBe('openai')
        ->and($first->attributes()['error.type'])->toBe(RateLimitedException::class)
        ->and($second->status())->toBe(SpanStatus::Ok)
        ->and($second->attributes()['gen_ai.provider.name'])->toBe('anthropic')
        ->and($second->attributes()['gen_ai.request.model'])->toBe('claude-test')
        ->and(aiSpan($spans, 'chat claude-test')->parentSpanId)->toBe($second->spanId);
});

it('marks a streamed run and its model calls as streaming', function () {
    fakeWeatherRun();

    $text = '';

    foreach ((new AiTestAssistant)->stream('Weather?', provider: 'openai', model: 'gpt-test') as $event) {
        if ($event instanceof TextDelta) {
            $text .= $event->delta;
        }
    }

    $spans = aiSpans($this->collector);
    $agent = aiSpan($spans, 'invoke_agent AiTestAssistant');

    expect($text)->toBe('It is sunny in Aarhus.')
        ->and($agent->status())->toBe(SpanStatus::Ok)
        ->and($agent->attributes()['gen_ai.request.stream'])->toBeTrue()
        ->and(aiSpan($spans, 'chat gpt-test', 0)->attributes()['gen_ai.request.stream'])->toBeTrue()
        ->and(aiSpan($spans, 'execute_tool AiTestWeatherTool')->parentSpanId)->toBe($agent->spanId)
        ->and(Telemetry::currentSpan())->toBeNull();
});

it('nests an agent used as a tool under the execute_tool span that ran it', function () {
    AiTestOrchestrator::fake([
        new ToolCall('call_9', 'researcher', ['task' => 'find it']),
        'Done.',
    ]);
    AiTestResearcher::fake(['Found it.']);

    (new AiTestOrchestrator)->prompt('Research this', provider: 'openai', model: 'gpt-test');

    $spans = aiSpans($this->collector);
    $outer = aiSpan($spans, 'invoke_agent AiTestOrchestrator');
    $tool = aiSpan($spans, 'execute_tool researcher');
    $inner = aiSpan($spans, 'invoke_agent researcher');

    expect($tool->parentSpanId)->toBe($outer->spanId)
        ->and($inner->parentSpanId)->toBe($tool->spanId)
        ->and($inner->attributes()['gen_ai.agent.name'])->toBe('researcher')
        ->and($inner->attributes()['gen_ai.agent.description'])->toBe('Looks things up')
        ->and($inner->traceId)->toBe($outer->traceId);
});

it('names an anonymous agent invoke_agent without a name', function () {
    AnonymousAgent::fake(['hello']);

    Laravel\Ai\agent('Be brief.')->prompt('hi', provider: 'openai', model: 'gpt-test');

    $agent = aiSpan(aiSpans($this->collector), 'invoke_agent');

    expect($agent->attributes())->not->toHaveKey('gen_ai.agent.name');
});

it('traces an embeddings call with its token usage', function () {
    $fake = Telemetry::fake();

    Embeddings::fake(fn ($prompt) => new EmbeddingsResponse([[0.1, 0.2]], new Usage(inputTokens: 8), new Meta('openai', 'text-embedding-test')));

    Embeddings::for(['hello'])->dimensions(2)->generate('openai', 'text-embedding-test');

    $span = $fake->recordedSpans('embeddings text-embedding-test')[0];

    expect($span->kind)->toBe(SpanKind::Client)
        ->and($span->status())->toBe(SpanStatus::Ok)
        ->and($span->attributes())->toMatchArray([
            'gen_ai.operation.name' => 'embeddings',
            'gen_ai.provider.name' => 'openai',
            'gen_ai.request.model' => 'text-embedding-test',
            'gen_ai.embeddings.dimension.count' => 2,
            'gen_ai.usage.input_tokens' => 8,
        ]);

    $labels = ['gen_ai.operation.name' => 'embeddings', 'gen_ai.provider.name' => 'openai', 'gen_ai.request.model' => 'text-embedding-test'];

    expect($fake->histogramCount('gen_ai.client.operation.duration', $labels))->toBe(1)
        ->and($fake->histogramSum('gen_ai.client.token.usage', [...$labels, 'gen_ai.token.type' => 'input']))->toBe(8.0);
});

it('fails an embeddings span when the provider fails over', function () {
    $fake = Telemetry::fake();
    $calls = 0;

    Embeddings::fake(function () use (&$calls) {
        if ($calls++ === 0) {
            throw RateLimitedException::forProvider('openai');
        }

        return [[0.1, 0.2]];
    });

    Embeddings::for(['hello'])->dimensions(2)->generate(['openai' => 'embed-a', 'gemini' => 'embed-b']);

    $failed = $fake->recordedSpans('embeddings embed-a')[0];
    $ok = $fake->recordedSpans('embeddings embed-b')[0];

    expect($failed->status())->toBe(SpanStatus::Error)
        ->and($failed->attributes()['error.type'])->toBe(RateLimitedException::class)
        ->and($ok->status())->toBe(SpanStatus::Ok)
        ->and($ok->attributes()['gen_ai.provider.name'])->toBe('gcp.gemini');

    $fake->assertHistogramRecorded('gen_ai.client.operation.duration', [
        'gen_ai.operation.name' => 'embeddings',
        'gen_ai.provider.name' => 'openai',
        'gen_ai.request.model' => 'embed-a',
        'error.type' => RateLimitedException::class,
    ]);
});

it('never throws into the app when an event arrives out of order', function () {
    $agent = new AiTestAssistant;
    $provider = Ai::textProvider('openai');

    // Ends nothing it never started, starts nothing twice.
    event(new Events\StepCompleted('nope', 0, $agent, $provider, 'gpt-test', true,
        new StepResponse('', [], FinishReason::Stop, new TextUsage, new Meta), 1.0));
    event(new Events\ToolInvoked('nope', 'nope', $agent, new AiTestWeatherTool, [], 'x', 1.0));
    event(new Events\AgentFailed('nope', new AgentPrompt($agent, 'x', [], $provider, 'gpt-test'), new RuntimeException));

    expect(aiSpans($this->collector))->toBeEmpty()
        ->and(Telemetry::currentSpan())->toBeNull();
});

it('runs captured content through the redaction engine on export', function () {
    rearmAi(new GenAiContent(2048));

    AiTestAssistant::fake(['done']);

    $secret = 'Q2hhbmdlTWVQbGVhc2VUaGlzSXNBVG9rZW4';

    (new AiTestAssistant)->prompt("call it with Authorization: Bearer {$secret} please", provider: 'openai', model: 'gpt-test');

    $input = aiSpan(aiSpans($this->collector), 'invoke_agent AiTestAssistant')->attributes()['gen_ai.input.messages'];

    expect($input)->not->toContain($secret)
        ->and(json_decode($input, true))->toBeArray();
});

it('works with Telemetry::fake() the way the docs show', function () {
    $telemetry = Telemetry::fake();
    AiTestAssistant::fake(['Your order shipped yesterday.']);

    (new AiTestAssistant)->prompt('Where is my order?', provider: 'openai', model: 'gpt-test');

    $telemetry->assertSpanRecorded('invoke_agent AiTestAssistant');
    $telemetry->assertSpanRecorded('chat gpt-test');
    $telemetry->assertHistogramRecorded('gen_ai.client.operation.duration');
});

it('costs each priced model call and sums the run, billing cache reads at their own price', function () {
    rearmAi(null, new GenAiPricing([
        // Matched by the snapshot the provider served, not the model asked for.
        'openai/gpt-test-*' => ['input' => 2.00, 'output' => 10.00, 'cache_read' => 0.50],
    ]));

    $fake = Telemetry::fake();
    fakeWeatherRun();

    (new AiTestAssistant)->prompt('Weather?', provider: 'openai', model: 'gpt-test');

    // 20 uncached × 2 + 100 cache reads × 0.5 + 14 output × 10, per 1M tokens.
    $expected = (20 * 2.00 + 100 * 0.50 + 14 * 10.00) / 1_000_000;

    $agent = $fake->recordedSpans('invoke_agent AiTestAssistant')[0];
    $calls = $fake->recordedSpans('chat gpt-test');

    expect($calls[0]->attributes())->not->toHaveKey('gen_ai.usage.cost') // the tool-call step used no tokens
        ->and($calls[1]->attributes()['gen_ai.usage.cost'])->toEqualWithDelta($expected, 1e-12)
        ->and($agent->attributes()['gen_ai.usage.cost'])->toEqualWithDelta($expected, 1e-12)
        ->and($fake->counterValue('gen_ai.client.cost', [
            'gen_ai.operation.name' => 'chat', 'gen_ai.provider.name' => 'openai', 'gen_ai.request.model' => 'gpt-test',
        ]))->toEqualWithDelta($expected, 1e-12);
});

it('records no cost for a model nobody priced, on the call or the run', function () {
    rearmAi(null, new GenAiPricing(['anthropic/*' => ['input' => 3.00, 'output' => 15.00]]));

    $fake = Telemetry::fake();
    fakeWeatherRun();

    (new AiTestAssistant)->prompt('Weather?', provider: 'openai', model: 'gpt-test');

    $attributes = collect($fake->recordedSpans())->flatMap(fn (Span $span) => array_keys($span->attributes()));

    expect($attributes)->not->toContain('gen_ai.usage.cost');
    $fake->assertCounterNotIncremented('gen_ai.client.cost');
});

it('costs an embeddings call from its input tokens', function () {
    rearmAi(null, new GenAiPricing(['text-embedding-test' => ['input' => 0.02]], 'EUR'));

    $fake = Telemetry::fake();

    Embeddings::fake(fn ($prompt) => new EmbeddingsResponse([[0.1, 0.2]], new Usage(inputTokens: 1_000_000), new Meta('openai', 'text-embedding-test')));

    Embeddings::for(['hello'])->generate('openai', 'text-embedding-test');

    $labels = ['gen_ai.operation.name' => 'embeddings', 'gen_ai.provider.name' => 'openai', 'gen_ai.request.model' => 'text-embedding-test'];

    expect($fake->recordedSpans('embeddings text-embedding-test')[0]->attributes()['gen_ai.usage.cost'])->toEqualWithDelta(0.02, 1e-12)
        ->and($fake->counterValue('gen_ai.client.cost', $labels))->toEqualWithDelta(0.02, 1e-12);
});
