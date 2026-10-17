<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\SpanStatus;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\Providers\Provider;
use Laravel\Ai\Contracts\RemembersConversations;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\ProviderFailedOver;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Tools\ToolNameResolver;
use Throwable;

/**
 * OpenTelemetry GenAI spans and metrics for the official Laravel AI SDK
 * (`laravel/ai`), built from its own events — no dependency on it beyond
 * the event classes, guarded by `class_exists()` at registration.
 *
 * One agent run becomes:
 *
 *   invoke_agent {agent}            INTERNAL  PromptingAgent → AgentPrompted / AgentFailed
 *   ├─ chat {model}                 CLIENT    StartingStep → StepCompleted / StepFailed
 *   ├─ execute_tool {tool}          INTERNAL  InvokingTool → ToolInvoked / ToolFailed
 *   │   └─ invoke_agent {sub-agent}           (an agent used as a tool nests here)
 *   └─ chat {model}
 *
 * Tools are siblings of the model call that asked for them, not its
 * children: laravel/ai runs them after that call has returned, and a span
 * cannot contain work that starts after it ends. The HTTP request a model
 * call makes nests under its `chat` span.
 *
 * Embeddings become `embeddings {model}` (CLIENT). Metrics follow the
 * GenAI conventions as last released (semconv v1.41.1):
 * `gen_ai.client.operation.duration` (s) and `gen_ai.client.token.usage`
 * ({token}, by `gen_ai.token.type`), recorded per model call — never per
 * agent run, which would count the same tokens twice.
 *
 * Prompt and completion content is NOT recorded unless
 * `instrument.ai_content` is on; see GenAiContent.
 *
 * With prices configured (`instrument.ai_pricing`, see GenAiPricing) each
 * priced model call also carries `gen_ai.usage.cost` and adds to the
 * `gen_ai.client.cost` counter; an agent run carries the sum of its calls,
 * but only when every call that used tokens was priced. Neither name is in
 * the conventions yet — they have no cost attribute.
 *
 * Every open span is held under laravel/ai's own invocation ids. A run that
 * never reports its end — a stream the caller stopped reading — leaves its
 * spans unexported, the same "a missing span beats a wrong one" choice the
 * tracer makes; the bookkeeping is capped so a long-lived worker cannot
 * accumulate them.
 */
final class LaravelAiInstrumentation
{
    /** Bucket boundaries the conventions specify for each histogram. */
    private const DURATION_BUCKETS = [0.01, 0.02, 0.04, 0.08, 0.16, 0.32, 0.64, 1.28, 2.56, 5.12, 10.24, 20.48, 40.96, 81.92];

    private const TOKEN_BUCKETS = [1, 4, 16, 64, 256, 1024, 4096, 16384, 65536, 262144, 1048576, 4194304, 16777216, 67108864];

    /** Most runs (and steps, tools, embeddings calls) tracked at once. */
    private const MAX_OPEN = 128;

    /**
     * laravel/ai driver → `gen_ai.provider.name` well-known value. A driver
     * not listed is reported as itself.
     */
    private const PROVIDERS = [
        'anthropic' => 'anthropic',
        'azure' => 'azure.ai.openai',
        'bedrock' => 'aws.bedrock',
        'cohere' => 'cohere',
        'deepseek' => 'deepseek',
        'gemini' => 'gcp.gemini',
        'groq' => 'groq',
        'mistral' => 'mistral_ai',
        'openai' => 'openai',
        'xai' => 'x_ai',
    ];

    /** @var array<string, array{span: Span, stream: bool, finish: ?string, cost: float, unpriced: bool}> invocation id → open agent run */
    private array $agents = [];

    /** @var array<string, array{span: Span, invocation: string}> "{invocation}#{step}" → open model call */
    private array $chats = [];

    /** @var array<string, array{span: Span, invocation: string}> tool invocation id → open tool call */
    private array $tools = [];

    /** @var array<string, array{span: Span, provider: string, model: string}> invocation id → open embeddings call */
    private array $embeddings = [];

    public function __construct(
        private readonly Container $container,
        private readonly ?GenAiContent $content = null,
        private readonly ?GenAiPricing $pricing = null,
    ) {}

    private function telemetry(): TelemetryManager
    {
        return $this->container->make(TelemetryManager::class);
    }

    public function register(Dispatcher $events): void
    {
        // Laravel's dispatcher does not deliver a subclass to its parent's
        // listeners, so the streaming variants are listened to by name.
        $events->listen(PromptingAgent::class, $this->agentStarting(...));
        $events->listen(StreamingAgent::class, $this->agentStarting(...));
        $events->listen(AgentPrompted::class, $this->agentFinished(...));
        $events->listen(AgentStreamed::class, $this->agentFinished(...));
        $events->listen(AgentFailed::class, $this->agentFailed(...));
        $events->listen(AgentFailedOver::class, $this->agentFailedOver(...));

        $events->listen(StartingStep::class, $this->stepStarting(...));
        $events->listen(StepCompleted::class, $this->stepCompleted(...));
        $events->listen(StepFailed::class, $this->stepFailed(...));

        $events->listen(InvokingTool::class, $this->toolStarting(...));
        $events->listen(ToolInvoked::class, $this->toolInvoked(...));
        $events->listen(ToolFailed::class, $this->toolFailed(...));

        $events->listen(GeneratingEmbeddings::class, $this->embeddingsStarting(...));
        $events->listen(EmbeddingsGenerated::class, $this->embeddingsGenerated(...));
        $events->listen(ProviderFailedOver::class, $this->providerFailedOver(...));
    }

    // ---------------------------------------------------------------- agents

    private function agentStarting(PromptingAgent $event): void
    {
        FailSafe::guard(function () use ($event) {
            $prompt = $event->prompt;
            $name = $this->agentName($prompt->agent);

            $attributes = [
                'gen_ai.operation.name' => 'invoke_agent',
                'gen_ai.provider.name' => $this->providerName($prompt->provider),
                'gen_ai.agent.name' => $name,
                'gen_ai.request.model' => $prompt->model,
            ];

            if ($prompt->agent instanceof CanActAsTool) {
                $attributes['gen_ai.agent.description'] = (string) $prompt->agent->description();
            }

            if ($event instanceof StreamingAgent) {
                $attributes['gen_ai.request.stream'] = true;
            }

            $span = $this->telemetry()->tracer()->startSpan(
                $name === null ? 'invoke_agent' : 'invoke_agent '.$name,
                SpanKind::Internal,
                array_filter($attributes, static fn (mixed $value): bool => $value !== null),
            );

            if ($this->content !== null) {
                $this->captureAgentInput($span, $prompt);
            }

            $this->hold($this->agents, $event->invocationId, [
                'span' => $span,
                'stream' => $event instanceof StreamingAgent,
                'finish' => null,
                'cost' => 0.0,
                'unpriced' => false,
            ]);
        });
    }

    private function agentFinished(AgentPrompted $event): void
    {
        FailSafe::guard(function () use ($event) {
            $run = $this->agents[$event->invocationId] ?? null;

            if ($run === null) {
                return;
            }

            unset($this->agents[$event->invocationId]);
            $this->endChildren($event->invocationId);

            $span = $run['span'];
            $response = $event->response;

            $this->usage($span, $response->usage, cache: false);

            if (($model = $response->meta->model) !== null && $model !== '') {
                $span->setAttribute('gen_ai.response.model', $model);
            }

            if ($run['finish'] !== null) {
                $span->setAttribute('gen_ai.response.finish_reasons', $this->finishReasons($run['finish']));
            }

            // A partial sum would read as the run's cost and undercount it.
            if ($this->pricing !== null && ! $run['unpriced'] && $this->usedTokens($response->usage)) {
                $span->setAttribute('gen_ai.usage.cost', $run['cost']);
            }

            if (($conversation = $this->conversationId($event->prompt->agent, $response)) !== null) {
                $span->setAttribute('gen_ai.conversation.id', $conversation);
            }

            if ($this->content !== null) {
                $span->setAttribute('gen_ai.output.messages', $this->content->output($response->text, [], $run['finish']));
            }

            $span->setStatus(SpanStatus::Ok);
            $span->end();
        });
    }

    private function agentFailed(AgentFailed $event): void
    {
        FailSafe::guard(fn () => $this->failAgent($event->invocationId, $event->exception));
    }

    /**
     * One provider gave up and the run moves to the next. That attempt is
     * its own `invoke_agent` span, ended here as an error; the next
     * attempt reports PromptingAgent again under the same invocation id
     * and opens a fresh one.
     */
    private function agentFailedOver(AgentFailedOver $event): void
    {
        FailSafe::guard(fn () => $this->failAgent($event->invocationId, $event->exception));
    }

    private function failAgent(string $invocation, Throwable $exception): void
    {
        $run = $this->agents[$invocation] ?? null;

        if ($run === null) {
            return;
        }

        unset($this->agents[$invocation]);
        $this->endChildren($invocation);

        $run['span']->recordException($exception);
        $run['span']->end();
    }

    // ----------------------------------------------------------------- steps

    private function stepStarting(StartingStep $event): void
    {
        FailSafe::guard(function () use ($event) {
            $attributes = [
                'gen_ai.operation.name' => 'chat',
                'gen_ai.provider.name' => $this->providerName($event->provider),
                'gen_ai.request.model' => $event->model,
                'gen_ai.request.max_tokens' => $event->options?->maxTokens,
                'gen_ai.request.temperature' => $event->options?->temperature,
                'gen_ai.request.top_p' => $event->options?->topP,
            ];

            if ($this->agents[$event->invocationId]['stream'] ?? false) {
                $attributes['gen_ai.request.stream'] = true;
            }

            $span = $this->telemetry()->tracer()->startSpan(
                'chat '.$event->model,
                SpanKind::Client,
                array_filter($attributes, static fn (mixed $value): bool => $value !== null),
            );

            if ($this->content !== null) {
                $span->setAttribute('gen_ai.input.messages', $this->content->messages($event->messages));
            }

            $this->hold($this->chats, $event->invocationId.'#'.$event->stepNumber, [
                'span' => $span,
                'invocation' => $event->invocationId,
            ]);
        });
    }

    private function stepCompleted(StepCompleted $event): void
    {
        FailSafe::guard(function () use ($event) {
            $key = $event->invocationId.'#'.$event->stepNumber;
            $call = $this->chats[$key] ?? null;

            if ($call === null) {
                return;
            }

            unset($this->chats[$key]);

            $span = $call['span'];
            $response = $event->response;
            $finish = $response->finishReason->value;

            $this->usage($span, $response->usage, cache: true);
            $span->setAttribute('gen_ai.response.finish_reasons', $this->finishReasons($finish));

            $responseModel = $response->meta->model;

            if ($responseModel !== null && $responseModel !== '') {
                $span->setAttribute('gen_ai.response.model', $responseModel);
            }

            if ($this->content !== null) {
                $span->setAttribute('gen_ai.output.messages', $this->content->output($response->text, $response->toolCalls, $finish));
            }

            $cost = null;

            if ($this->pricing !== null && $this->usedTokens($response->usage)) {
                $cost = $this->pricing->textCost($this->providerNames($event->provider), $responseModel, $event->model, $response->usage);

                if ($cost !== null) {
                    $span->setAttribute('gen_ai.usage.cost', $cost);
                }
            }

            if (isset($this->agents[$event->invocationId])) {
                $this->agents[$event->invocationId]['finish'] = $finish;

                if ($cost !== null) {
                    $this->agents[$event->invocationId]['cost'] += $cost;
                } elseif ($this->usedTokens($response->usage)) {
                    $this->agents[$event->invocationId]['unpriced'] = true;
                }
            }

            $span->setStatus(SpanStatus::Ok);
            $span->end();

            $this->recordOperation($span, 'chat', $event->provider, $event->model, $responseModel, null, $response, cost: $cost);
        });
    }

    private function stepFailed(StepFailed $event): void
    {
        FailSafe::guard(function () use ($event) {
            $key = $event->invocationId.'#'.$event->stepNumber;
            $call = $this->chats[$key] ?? null;

            if ($call === null) {
                return;
            }

            unset($this->chats[$key]);

            $span = $call['span'];
            // A failed generation still owes the reader a finish reason:
            // the conventions say `error`, not an omission.
            $span->setAttribute('gen_ai.response.finish_reasons', $this->finishReasons('error'));
            $span->recordException($event->exception);
            $span->end();

            $this->recordOperation($span, 'chat', $event->provider, $event->model, null, $event->exception, null);
        });
    }

    // ----------------------------------------------------------------- tools

    private function toolStarting(InvokingTool $event): void
    {
        FailSafe::guard(function () use ($event) {
            $name = ToolNameResolver::resolve($event->tool);

            $span = $this->telemetry()->tracer()->startSpan('execute_tool '.$name, SpanKind::Internal, [
                'gen_ai.operation.name' => 'execute_tool',
                'gen_ai.tool.name' => $name,
                'gen_ai.tool.type' => 'function',
                'gen_ai.tool.description' => $this->toolDescription($event->tool),
            ]);

            if ($this->content !== null) {
                $span->setAttribute('gen_ai.tool.call.arguments', $this->content->arguments($event->arguments));
            }

            $this->hold($this->tools, $event->toolInvocationId, [
                'span' => $span,
                'invocation' => $event->invocationId,
            ]);
        });
    }

    private function toolInvoked(ToolInvoked $event): void
    {
        FailSafe::guard(function () use ($event) {
            $call = $this->tools[$event->toolInvocationId] ?? null;

            if ($call === null) {
                return;
            }

            unset($this->tools[$event->toolInvocationId]);

            if ($this->content !== null && ($result = $this->content->result($event->result)) !== null) {
                $call['span']->setAttribute('gen_ai.tool.call.result', $result);
            }

            $call['span']->setStatus(SpanStatus::Ok);
            $call['span']->end();
        });
    }

    private function toolFailed(ToolFailed $event): void
    {
        FailSafe::guard(function () use ($event) {
            $call = $this->tools[$event->toolInvocationId] ?? null;

            if ($call === null) {
                return;
            }

            unset($this->tools[$event->toolInvocationId]);

            $call['span']->recordException($event->exception);
            $call['span']->end();
        });
    }

    // ------------------------------------------------------------ embeddings

    /**
     * Detached, not ambient: laravel/ai reports no end for an embeddings
     * call that throws anything but a failover exception, and an ambient
     * span nobody closes would adopt everything the request did next. A
     * detached one nobody closes is simply never exported.
     */
    private function embeddingsStarting(GeneratingEmbeddings $event): void
    {
        FailSafe::guard(function () use ($event) {
            $provider = $this->providerName($event->provider);

            $span = $this->telemetry()->tracer()->startDetachedSpan('embeddings '.$event->model, SpanKind::Client, [
                'gen_ai.operation.name' => 'embeddings',
                'gen_ai.provider.name' => $provider,
                'gen_ai.request.model' => $event->model,
                'gen_ai.embeddings.dimension.count' => $event->prompt->dimensions > 0 ? $event->prompt->dimensions : null,
            ]);

            $this->hold($this->embeddings, $event->invocationId, [
                'span' => $span,
                'provider' => $event->provider->name(),
                'model' => $event->model,
            ]);
        });
    }

    private function embeddingsGenerated(EmbeddingsGenerated $event): void
    {
        FailSafe::guard(function () use ($event) {
            $call = $this->embeddings[$event->invocationId] ?? null;

            if ($call === null) {
                return;
            }

            unset($this->embeddings[$event->invocationId]);

            $span = $call['span'];
            $usage = $event->response->usage;

            if ($usage->inputTokens > 0) {
                $span->setAttribute('gen_ai.usage.input_tokens', $usage->inputTokens);
            }

            $responseModel = $event->response->meta->model;

            if ($responseModel !== null && $responseModel !== '') {
                $span->setAttribute('gen_ai.response.model', $responseModel);
            }

            $cost = $this->pricing !== null && $usage->inputTokens > 0
                ? $this->pricing->embeddingsCost($this->providerNames($event->provider), $responseModel, $event->model, $usage->inputTokens)
                : null;

            if ($cost !== null) {
                $span->setAttribute('gen_ai.usage.cost', $cost);
            }

            $span->setStatus(SpanStatus::Ok);
            $span->end();

            $this->recordOperation($span, 'embeddings', $event->provider, $event->model, $responseModel, null, null, $usage->inputTokens, $cost);
        });
    }

    /**
     * Carries no invocation id, so it closes the newest open embeddings
     * call against the same provider and model. Fired for images, audio
     * and the rest too — those have nothing open here and are ignored.
     */
    private function providerFailedOver(ProviderFailedOver $event): void
    {
        if ($event instanceof AgentFailedOver) {
            return;
        }

        FailSafe::guard(function () use ($event) {
            $provider = $event->provider->name();

            foreach (array_reverse($this->embeddings, preserve_keys: true) as $invocation => $call) {
                if ($call['provider'] !== $provider || $call['model'] !== $event->model) {
                    continue;
                }

                unset($this->embeddings[$invocation]);

                $call['span']->recordException($event->exception);
                $call['span']->end();

                $this->recordOperation($call['span'], 'embeddings', $event->provider, $event->model, null, $event->exception, null);

                return;
            }
        });
    }

    // --------------------------------------------------------------- helpers

    /**
     * A run that ends while one of its steps or tools is still open — an
     * error unwinding through it — takes them with it, so they leave the
     * context stack instead of adopting whatever runs next.
     */
    private function endChildren(string $invocation): void
    {
        foreach ($this->chats as $key => $call) {
            if ($call['invocation'] === $invocation) {
                unset($this->chats[$key]);
                $this->endAbandoned($call['span']);
            }
        }

        foreach ($this->tools as $key => $call) {
            if ($call['invocation'] === $invocation) {
                unset($this->tools[$key]);
                $this->endAbandoned($call['span']);
            }
        }
    }

    private function endAbandoned(Span $span): void
    {
        $span->setStatus(SpanStatus::Error, 'ended with its agent run');
        $span->end();
    }

    /**
     * @template T
     *
     * @param  array<string, T>  $map
     * @param  T  $entry
     */
    private function hold(array &$map, string $key, mixed $entry): void
    {
        // Oldest first out. A run that never reported its end is the only
        // way to get here; its span was never going to be exported.
        while (count($map) >= self::MAX_OPEN) {
            array_shift($map);
        }

        $map[$key] = $entry;
    }

    private function usage(Span $span, TextUsage $usage, bool $cache): void
    {
        if ($usage->inputTokens > 0) {
            $span->setAttribute('gen_ai.usage.input_tokens', $usage->inputTokens);
        }

        if ($usage->outputTokens > 0) {
            $span->setAttribute('gen_ai.usage.output_tokens', $usage->outputTokens);
        }

        if ($usage->reasoningTokens !== null && $usage->reasoningTokens > 0) {
            $span->setAttribute('gen_ai.usage.reasoning.output_tokens', $usage->reasoningTokens);
        }

        // Cache breakdowns belong on the model call: summed across a run's
        // calls (and models) they stop meaning anything.
        if (! $cache) {
            return;
        }

        if ($usage->cacheReadInputTokens !== null) {
            $span->setAttribute('gen_ai.usage.cache_read.input_tokens', $usage->cacheReadInputTokens);
        }

        if ($usage->cacheWriteInputTokens !== null) {
            $span->setAttribute('gen_ai.usage.cache_creation.input_tokens', $usage->cacheWriteInputTokens);
        }
    }

    private function recordOperation(
        Span $span,
        string $operation,
        Provider $provider,
        string $model,
        ?string $responseModel,
        ?Throwable $error,
        ?StepResponse $response,
        int $embeddingTokens = 0,
        ?float $cost = null,
    ): void {
        $labels = [
            'gen_ai.operation.name' => $operation,
            'gen_ai.provider.name' => $this->providerName($provider),
            'gen_ai.request.model' => $model,
        ];

        $telemetry = $this->telemetry();

        $telemetry->histogram(
            'gen_ai.client.operation.duration',
            buckets: self::DURATION_BUCKETS,
            description: 'GenAI operation duration',
            unit: 's',
        )->record($span->durationMs() / 1000, $error === null ? $labels : [...$labels, 'error.type' => $error::class]);

        if ($error !== null) {
            return;
        }

        // Only a count the provider actually reported: a zero is "not
        // reported" far more often than it is a real zero-token call, and
        // the conventions forbid inventing one.
        $tokens = $response !== null
            ? ['input' => $response->usage->inputTokens, 'output' => $response->usage->outputTokens]
            : ['input' => $embeddingTokens];

        foreach ($tokens as $type => $count) {
            if ($count <= 0) {
                continue;
            }

            $telemetry->histogram(
                'gen_ai.client.token.usage',
                buckets: self::TOKEN_BUCKETS,
                description: 'Number of input and output tokens used',
                unit: '{token}',
            )->record($count, [...$labels, 'gen_ai.token.type' => $type]);
        }

        if ($cost !== null && $this->pricing !== null) {
            $telemetry->counter(
                'gen_ai.client.cost',
                description: 'Cost of GenAI operations, from the configured model prices',
                unit: '{'.$this->pricing->currency.'}',
            )->inc($cost, $labels);
        }
    }

    private function usedTokens(TextUsage $usage): bool
    {
        return $usage->inputTokens > 0 || $usage->outputTokens > 0;
    }

    private function captureAgentInput(Span $span, AgentPrompt $prompt): void
    {
        if ($this->content === null) {
            return;
        }

        $instructions = $this->content->instructions((string) $prompt->agent->instructions());

        if ($instructions !== null) {
            $span->setAttribute('gen_ai.system_instructions', $instructions);
        }

        if ($prompt->prompt !== '') {
            $span->setAttribute('gen_ai.input.messages', $this->content->prompt($prompt->prompt));
        }
    }

    /**
     * The name laravel/ai itself uses when the agent is offered as a tool;
     * otherwise its class. An anonymous agent has no name worth reporting.
     */
    private function agentName(Agent $agent): ?string
    {
        if ($agent instanceof CanActAsTool) {
            return $agent->name();
        }

        if ($agent instanceof AnonymousAgent || str_contains($agent::class, '@anonymous')) {
            return null;
        }

        return class_basename($agent);
    }

    private function toolDescription(Tool $tool): ?string
    {
        $description = (string) $tool->description();

        return $description === '' ? null : $description;
    }

    private function providerName(Provider $provider): string
    {
        $driver = strtolower($provider->driver());

        return self::PROVIDERS[$driver] ?? $driver;
    }

    /**
     * Both names a price key may use for the provider: the laravel/ai
     * driver (`gemini`) and the conventions' name (`gcp.gemini`).
     *
     * @return list<string>
     */
    private function providerNames(Provider $provider): array
    {
        return array_values(array_unique([strtolower($provider->driver()), $this->providerName($provider)]));
    }

    /**
     * `gen_ai.response.finish_reasons` is a string array; span attributes
     * here are scalars, so it travels as its JSON encoding.
     */
    private function finishReasons(string $reason): string
    {
        return json_encode([$reason]) ?: '[]';
    }

    private function conversationId(Agent $agent, AgentResponse $response): ?string
    {
        if ($response->conversationId !== null && $response->conversationId !== '') {
            return $response->conversationId;
        }

        if ($agent instanceof RemembersConversations) {
            $current = $agent->currentConversation();

            return $current === null || $current === '' ? null : $current;
        }

        return null;
    }
}
