<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Stringable;

/**
 * Prompt and completion content in the OTel GenAI message shape, for the
 * opt-in `gen_ai.input.messages` / `gen_ai.output.messages` /
 * `gen_ai.system_instructions` / `gen_ai.tool.call.*` attributes.
 *
 * Only ever built when `instrument.ai_content` is on — the default is to
 * record no content at all, which is what the conventions ask for.
 *
 * Spans here hold scalars, so a structured value travels as a JSON string
 * (the conventions allow exactly that where structured attributes are not
 * supported). The byte budget is shared out across the text a value
 * carries and each piece is cut on a UTF-8 boundary, so the JSON stays
 * valid and a long history cannot make the attribute arbitrarily large.
 * Nothing is redacted here: every attribute still passes through the
 * redaction engine (patterns, `Telemetry::redactUsing()`, the value-length
 * cap) at flush, like any other.
 */
final class GenAiContent
{
    /** Smallest slice any one piece of text is cut to, however many share the budget. */
    private const MIN_PIECE_BYTES = 64;

    public const TRUNCATED = '…';

    public function __construct(private readonly int $maxBytes) {}

    /**
     * `gen_ai.system_instructions`: a list of text parts.
     */
    public function instructions(string $instructions): ?string
    {
        if ($instructions === '') {
            return null;
        }

        return $this->encode([['type' => 'text', 'content' => $this->cut($instructions, $this->maxBytes)]]);
    }

    /**
     * `gen_ai.input.messages` for a single user prompt.
     */
    public function prompt(string $prompt): string
    {
        return $this->encode([[
            'role' => 'user',
            'parts' => [['type' => 'text', 'content' => $this->cut($prompt, $this->maxBytes)]],
        ]]);
    }

    /**
     * `gen_ai.input.messages` for the history a step sends.
     *
     * @param  array<array-key, mixed>  $messages
     */
    public function messages(array $messages): string
    {
        $budget = $this->budget($this->pieces($messages));
        $out = [];

        foreach ($messages as $message) {
            if ($message instanceof Message) {
                $out[] = $this->message($message, $budget);
            }
        }

        return $this->encode($out);
    }

    /**
     * `gen_ai.output.messages`: one assistant message (laravel/ai returns
     * one candidate), its text and the tool calls it asked for.
     *
     * @param  array<array-key, mixed>  $toolCalls
     */
    public function output(string $text, array $toolCalls, ?string $finishReason): string
    {
        $calls = array_values(array_filter($toolCalls, static fn (mixed $call): bool => $call instanceof ToolCall));
        $budget = $this->budget(($text !== '' ? 1 : 0) + count($calls));

        $parts = $text !== '' ? [['type' => 'text', 'content' => $this->cut($text, $budget)]] : [];

        foreach ($calls as $call) {
            $parts[] = $this->toolCallPart($call, $budget);
        }

        $message = ['role' => 'assistant', 'parts' => $parts];

        if ($finishReason !== null) {
            $message['finish_reason'] = $finishReason;
        }

        return $this->encode([$message]);
    }

    /**
     * `gen_ai.tool.call.arguments`.
     *
     * @param  array<array-key, mixed>  $arguments
     */
    public function arguments(array $arguments): string
    {
        return $this->cut($this->encode($arguments === [] ? new \stdClass : $arguments), $this->maxBytes);
    }

    /**
     * `gen_ai.tool.call.result`.
     */
    public function result(mixed $result): ?string
    {
        $text = $this->stringify($result);

        return $text === null ? null : $this->cut($text, $this->maxBytes);
    }

    /**
     * Cut to at most `$bytes` bytes on a UTF-8 boundary, marked when cut.
     */
    public function cut(string $value, int $bytes): string
    {
        if (strlen($value) <= $bytes) {
            return $value;
        }

        $keep = max(0, $bytes - strlen(self::TRUNCATED));

        return mb_strcut($value, 0, $keep, 'UTF-8').self::TRUNCATED;
    }

    /**
     * @return array<string, mixed>
     */
    private function message(Message $message, int $budget): array
    {
        if ($message instanceof ToolResultMessage) {
            $parts = [];

            foreach ($message->toolResults as $result) {
                if ($result instanceof ToolResult) {
                    $parts[] = [
                        'type' => 'tool_call_response',
                        'id' => $result->id,
                        'response' => $this->cut($this->stringify($result->result) ?? '', $budget),
                    ];
                }
            }

            return ['role' => 'tool', 'parts' => $parts];
        }

        $parts = [];
        $content = $message->content ?? '';

        if ($content !== '') {
            $parts[] = ['type' => 'text', 'content' => $this->cut($content, $budget)];
        }

        if ($message instanceof AssistantMessage) {
            foreach ($message->toolCalls as $call) {
                if ($call instanceof ToolCall) {
                    $parts[] = $this->toolCallPart($call, $budget);
                }
            }
        }

        return [
            'role' => $message instanceof UserMessage ? 'user' : ($message instanceof AssistantMessage ? 'assistant' : $message->role->value),
            'parts' => $parts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toolCallPart(ToolCall $call, int $budget): array
    {
        $arguments = $this->encode($call->arguments === [] ? new \stdClass : $call->arguments);

        return [
            'type' => 'tool_call',
            'id' => $call->id,
            'name' => $call->name,
            // Kept structured while it fits; a cut JSON document is not
            // JSON, so an oversized one travels as the cut string instead.
            'arguments' => strlen($arguments) <= $budget ? ($call->arguments === [] ? new \stdClass : $call->arguments) : $this->cut($arguments, $budget),
        ];
    }

    /**
     * How many pieces of text the history carries, to share the budget.
     *
     * @param  array<array-key, mixed>  $messages
     */
    private function pieces(array $messages): int
    {
        $count = 0;

        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage) {
                $count += $message->toolResults->count();

                continue;
            }

            if ($message instanceof Message) {
                $count += ($message->content ?? '') !== '' ? 1 : 0;
            }

            if ($message instanceof AssistantMessage) {
                $count += $message->toolCalls->count();
            }
        }

        return $count;
    }

    private function budget(int $pieces): int
    {
        return max(self::MIN_PIECE_BYTES, intdiv($this->maxBytes, max(1, $pieces)));
    }

    private function stringify(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_string($value) => $value,
            is_scalar($value) => var_export($value, true),
            $value instanceof Stringable => (string) $value,
            default => $this->encode($value),
        };
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '[]';
    }
}
