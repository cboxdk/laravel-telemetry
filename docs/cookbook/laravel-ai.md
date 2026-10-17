---
title: "Cookbook: Laravel AI SDK"
description: OpenTelemetry GenAI traces and token metrics for laravel/ai agents, tools and embeddings
weight: 8
---

# Cookbook: Laravel AI SDK

Goal: see every agent run, model call and tool call your app makes through
[`laravel/ai`](https://github.com/laravel/ai) as part of the trace it ran
in, and graph latency and token spend per provider and model — in the
OpenTelemetry GenAI vocabulary your backend already understands.

There is nothing to install or wire. When `laravel/ai` is in the app, the
package listens to its own events (`instrument.ai`, default on). When it is
not, nothing is registered — no listeners, no cost.

## What a run looks like

```text
GET /chat                                   (your request span)
└─ invoke_agent SupportAgent                INTERNAL
   ├─ chat gpt-5-mini                       CLIENT   ← the model asks for a tool
   │  └─ POST api.openai.com                CLIENT   (HTTP client span)
   ├─ execute_tool LookupOrder              INTERNAL
   │  └─ select * from orders …                      (whatever the tool did)
   └─ chat gpt-5-mini                       CLIENT   ← the model answers
```

- **`invoke_agent {agent}`** — one per `prompt()`/`stream()` run. The name
  is the agent's class, or its `name()` when it implements `CanActAsTool`;
  an anonymous `agent(...)` is just `invoke_agent`.
- **`chat {model}`** — one per model call (laravel/ai calls them steps). A
  run that uses tools makes several.
- **`execute_tool {tool}`** — one per tool call. Tools are siblings of the
  model call that asked for them, not children: laravel/ai runs a tool after
  that call has returned.
- **An agent used as a tool** nests its own `invoke_agent` under the
  `execute_tool` span that ran it, so a multi-agent run reads as one tree.
- **`embeddings {model}`** — one per `Embeddings::for(...)->generate()` that
  reaches the provider (a cache hit makes no call and no span).

Queued agents (`->queue()`) continue the trace of the request that queued
them, like any other job.

### Attributes

| Span | Attributes |
|------|------------|
| all | `gen_ai.operation.name`, `error.type` + error status on failure |
| `invoke_agent` | `gen_ai.provider.name`, `gen_ai.agent.name`, `gen_ai.agent.description` (`CanActAsTool`), `gen_ai.request.model`, `gen_ai.response.model`, `gen_ai.usage.input_tokens` / `output_tokens` / `reasoning.output_tokens` (summed over the run), `gen_ai.response.finish_reasons`, `gen_ai.conversation.id` (remembered conversations), `gen_ai.request.stream` |
| `chat` | `gen_ai.provider.name`, `gen_ai.request.model`, `gen_ai.response.model`, `gen_ai.request.max_tokens` / `temperature` / `top_p` when set, `gen_ai.usage.input_tokens` / `output_tokens` / `reasoning.output_tokens` / `cache_read.input_tokens` / `cache_creation.input_tokens`, `gen_ai.response.finish_reasons`, `gen_ai.request.stream` |
| `execute_tool` | `gen_ai.tool.name`, `gen_ai.tool.description`, `gen_ai.tool.type` (`function`) |
| `embeddings` | `gen_ai.provider.name`, `gen_ai.request.model`, `gen_ai.response.model`, `gen_ai.embeddings.dimension.count`, `gen_ai.usage.input_tokens` |

`gen_ai.provider.name` uses the conventions' well-known values where one
exists (`openai`, `anthropic`, `gcp.gemini`, `azure.ai.openai`,
`aws.bedrock`, `mistral_ai`, `x_ai`, `groq`, `deepseek`, `cohere`); any
other driver is reported as itself (`ollama`, `openrouter`, …).
`gen_ai.response.finish_reasons` is a string array in the conventions;
span attributes here are scalars, so it travels as its JSON encoding
(`["stop"]`). A token count the provider did not report is left out, never
recorded as zero.

## Metrics

| Metric | Prometheus | Labels |
|--------|------------|--------|
| `gen_ai.client.operation.duration` (histogram, s) | `gen_ai_client_operation_duration_seconds` | `gen_ai.operation.name` (`chat` / `embeddings`), `gen_ai.provider.name`, `gen_ai.request.model`, `error.type` on failure |
| `gen_ai.client.token.usage` (histogram, `{token}`) | `gen_ai_client_token_usage` | the same, plus `gen_ai.token.type` (`input` / `output`) |
| `gen_ai.client.cost` (counter, `{USD}`) — only with [prices](#costs) | `gen_ai_client_cost_total` | the same as the duration, without `error.type` |

Both use the bucket boundaries the conventions specify, and both are
recorded per model call — never per agent run, which would count the same
tokens twice. Labels are the operation, provider and requested model:
bounded by your configuration, never by what users type.

Token spend per model over the last day:

```promql
sum by (gen_ai_request_model, gen_ai_token_type) (
  increase(gen_ai_client_token_usage_sum[1d])
)
```

p95 model latency, and the error rate, per provider:

```promql
histogram_quantile(0.95, sum by (le, gen_ai_provider_name) (
  rate(gen_ai_client_operation_duration_seconds_bucket{gen_ai_operation_name="chat"}[5m])
))

sum by (gen_ai_provider_name) (rate(gen_ai_client_operation_duration_seconds_count{error_type!=""}[5m]))
  / sum by (gen_ai_provider_name) (rate(gen_ai_client_operation_duration_seconds_count[5m]))
```

Slow agent runs in Tempo:

```traceql
{ span.gen_ai.operation.name = "invoke_agent" && duration > 10s }
```

## Costs

Give the package your model prices and every priced model call carries
`gen_ai.usage.cost` and adds to the `gen_ai.client.cost` counter. No price
list ships with the package: prices change, differ per contract and region,
and a stale catalog is a wrong number that looks right. A model you have
not priced gets no cost at all, never a guess.

```php
// config/telemetry.php
'instrument' => [
    'ai_pricing' => [
        'currency' => env('TELEMETRY_AI_CURRENCY', 'USD'),
        // Per 1M tokens.
        'models' => [
            'anthropic/claude-sonnet-*' => ['input' => 3.00, 'output' => 15.00, 'cache_read' => 0.30, 'cache_write' => 3.75],
            'openai/gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60, 'cache_read' => 0.075],
            'openai/text-embedding-3-small' => ['input' => 0.02],
        ],
    ],
],
```

- **Keys** name a model, optionally prefixed by its provider: the laravel/ai
  driver (`gemini/…`) or the `gen_ai.provider.name` value (`gcp.gemini/…`).
  `*` matches dated snapshots. An exact key wins over a pattern, and a
  longer pattern over a shorter one. Matching ignores case.
- **The served model is priced first.** The model the provider reports
  (`gen_ai.response.model`, often a dated snapshot) is looked up before the
  one you asked for.
- **Each token is billed once.** Input tokens include cache reads and
  writes, so the uncached part is billed at `input`, cache reads at
  `cache_read` and cache writes at `cache_write`. Both fall back to `input`.
  Reasoning tokens are part of the output and billed at `output`.
  Embeddings are billed at `input`.
- **An agent run** (`invoke_agent`) carries the sum of its model calls,
  but only when every call that used tokens was priced. A partial sum
  would undercount the run.
- **The counter** has the same labels as the token metric. Its unit is the
  currency (`{EUR}` with `TELEMETRY_AI_CURRENCY=EUR`). One app reports in one
  currency.

The conventions define no cost attribute yet, so `gen_ai.usage.cost` and
`gen_ai.client.cost` are this package's names.

Spend per model over the last day, and the models that used tokens but have
no price:

```promql
sum by (gen_ai_request_model) (increase(gen_ai_client_cost_total[1d]))

sum by (gen_ai_provider_name, gen_ai_request_model) (increase(gen_ai_client_token_usage_count[1d]))
  unless on (gen_ai_provider_name, gen_ai_request_model)
sum by (gen_ai_provider_name, gen_ai_request_model) (increase(gen_ai_client_cost_total[1d]))
```

The most expensive runs in Tempo:

```traceql
{ span.gen_ai.operation.name = "invoke_agent" && span.gen_ai.usage.cost > 0.5 }
```

## Per user, team and tenant

Every AI span carries what you need to break usage down by who caused it:

- **The signed-in user** (`user.id`), when the request has already
  resolved one. The instrumentation never resolves it itself, so it adds
  no auth query. Off with `instrument.user`.
- **Your dimensions** from `Telemetry::context()` (team, tenant, plan, …),
  set once in middleware. Queued jobs inherit them.
- **The agent** (`gen_ai.agent.name`), on the agent run and on each of its
  model calls.

```traceql
{ span.tenant.id = "7" && span.gen_ai.operation.name = "chat" }
```

Metric labels stay bounded, so ids never become labels. Two opt-ins cover
the totals:

**Bounded dimensions as labels.** `instrument.ai_metric_labels` adds
`Telemetry::context()` keys with few values to all three `gen_ai.client.*`
metrics, for exact totals in Prometheus:

```php
'ai_metric_labels' => ['plan'],   // pro / team / free — never an id
```

```promql
sum by (plan) (increase(gen_ai_client_cost_total[30d]))
```

**One record per call, for exact per-id totals.** `instrument.ai_usage_events`
(`TELEMETRY_INSTRUMENT_AI_USAGE_EVENTS=true`) records a
`gen_ai.client.inference.operation.details` log record for every model
call, linked to its span. It carries the call's `gen_ai.*` attributes
(models, tokens with cache and reasoning, finish reason, cost), `user.id`,
your context dimensions and `error.type`. It never carries prompt or
completion content, even with `instrument.ai_content` on. Log records are
never sampled, so sums over them are exact whatever the trace sample rate:

```logql
# Spend per tenant, last 30 days
sum by (tenant_id) (
  sum_over_time({service_name="my-app"} |= "gen_ai.client.inference.operation.details" | unwrap gen_ai_usage_cost [30d])
)

# Top users by tokens today
topk(10, sum by (user_id) (
  sum_over_time({service_name="my-app"} |= "gen_ai.client.inference.operation.details" | unwrap gen_ai_usage_input_tokens [1d])
))
```

These are operational numbers. If you invoice on them, reconcile against
the provider's own usage export.

## Prompts and completions (opt-in)

By default **no content is recorded** — not the prompt, not the answer,
not the system instructions, not tool arguments or results. That is what
the GenAI conventions ask for: it is user data.

To record it, for a staging environment or a deliberate production
decision:

```dotenv
TELEMETRY_INSTRUMENT_AI_CONTENT=true
TELEMETRY_INSTRUMENT_AI_CONTENT_MAX_BYTES=2048
```

Spans then also carry, as JSON in the conventions' message shape:

- `invoke_agent`: `gen_ai.system_instructions`, `gen_ai.input.messages`
  (the prompt), `gen_ai.output.messages` (the answer)
- `chat`: `gen_ai.input.messages` (the full history sent: prompt, earlier
  tool calls, tool results) and `gen_ai.output.messages` (text and the tool
  calls the model asked for)
- `execute_tool`: `gen_ai.tool.call.arguments`, `gen_ai.tool.call.result`

Attachments are never recorded. The byte budget applies to each attribute
and is shared across the messages it carries, so a long history cannot
grow without bound; every piece is cut on a UTF-8 boundary and marked with
`…`, and the JSON stays valid.

Captured content goes through the same redaction as every other attribute
when it is exported: the credential patterns (JWTs, `Bearer`/`Basic`
tokens, credentials in URLs — not every secret-looking word in free text),
the personal-identifier detectors if you turned them on (`redaction.pii` —
worth doing before you record what users type), your own
`Telemetry::redactUsing()` hook, and
`redaction.max_value_length` (4096 by default), which caps the whole
attribute after redaction. Keep the content budget below that cap, or a
long value is cut mid-JSON. To scrub something specific to your domain:

```php
// Return the replacement, or null to keep the value.
Telemetry::redactUsing(function (string $key, string $value): ?string {
    if (! str_starts_with($key, 'gen_ai.')) {
        return null;
    }

    return preg_replace('/\bORD-\d{6}\b/', '[ORDER]', $value);
});
```

See [Runtime hooks](../extension-points/hooks.md) for the full redaction
model.

## Failures and failover

- A model call that throws ends its `chat` span with the exception and
  `gen_ai.response.finish_reasons = ["error"]`; its duration is recorded
  with `error.type`, and no tokens are.
- A tool that throws fails its `execute_tool` span; the run fails with it.
- **Failover**: when an agent has several providers and one gives up with a
  failover exception (rate limited, overloaded, …), that attempt is its own
  `invoke_agent` span, ended as an error; the next provider's attempt is a
  new `invoke_agent` span in the same trace. An embeddings call that fails
  over does the same with its `embeddings` span.

Your application sees every exception exactly as it would without the
package — telemetry never throws, and never swallows.

## Limits

- **A stream nobody finishes reading** reports no end, so its spans are
  never exported: a missing span is preferred to one with a made-up
  duration. Read the stream to the end (returning it from a controller
  does) and the run is recorded. Until the request ends, spans started
  after an abandoned stream nest under its run; the request's own span and
  its tallies are unaffected.
- **An embeddings call that throws something other than a failover
  exception** reports no end either; its span is dropped for the same
  reason. Embeddings spans are not the ambient span for that reason, so the
  provider's HTTP span sits beside it rather than under it.
- **Tool call ids**: laravel/ai's tool events do not carry the provider's
  call id, so `execute_tool` has no `gen_ai.tool.call.id`. With content
  capture on, the `chat` span's output still lists each call with its id.

## Conventions version

Names follow the OpenTelemetry GenAI semantic conventions as last released
with the main conventions (**v1.41.1**). The conventions have since moved to
their own repository, where unreleased work replaces
`gen_ai.client.token.usage` with per-direction counters
(`gen_ai.client.inference.usage.input_tokens`, …), adds
`gen_ai.client.inference.duration`, and renames
`gen_ai.usage.cache_creation.input_tokens` to `…cache_write…`. This package
will follow once that is released; the span names and the attributes above
are the same in both.

## Turning it off

```dotenv
TELEMETRY_INSTRUMENT_AI=false
```

## Testing

```php
use Cbox\Telemetry\Facades\Telemetry;

it('answers within budget', function () {
    $telemetry = Telemetry::fake();
    SupportAgent::fake(['Your order shipped yesterday.']);

    (new SupportAgent)->prompt('Where is my order?');

    $telemetry->assertSpanRecorded('invoke_agent SupportAgent');
    $telemetry->assertHistogramRecorded('gen_ai.client.operation.duration');
});
```
