<?php

declare(strict_types=1);

use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\SpanRules;

function ruleSpan(string $name, array $attributes = []): Span
{
    return new Span(
        traceId: str_repeat('a', 32),
        spanId: str_repeat('b', 16),
        parentSpanId: null,
        name: $name,
        kind: SpanKind::Internal,
        sampled: true,
        attributes: $attributes,
        onEnd: static fn () => null,
    );
}

it('matches when every condition of any one rule matches', function () {
    $rules = SpanRules::fromConfig([
        ['gen_ai.operation.name' => 'invoke_agent', 'tenant.id' => '7'],
        ['name' => 'redis *'],
    ]);

    expect($rules->matches(ruleSpan('invoke_agent X', ['gen_ai.operation.name' => 'invoke_agent', 'tenant.id' => 7])))->toBeTrue()
        ->and($rules->matches(ruleSpan('invoke_agent X', ['gen_ai.operation.name' => 'invoke_agent', 'tenant.id' => 8])))->toBeFalse()
        ->and($rules->matches(ruleSpan('REDIS GET')))->toBeTrue()
        ->and($rules->matches(ruleSpan('db.query')))->toBeFalse();
});

it('takes a list of patterns, and never matches an attribute the span lacks', function () {
    $rules = SpanRules::fromConfig([['http.route' => ['checkout*', 'api/orders*'], 'plan' => '*']]);

    expect($rules->matches(ruleSpan('r', ['http.route' => 'api/orders/{order}', 'plan' => 'pro'])))->toBeTrue()
        ->and($rules->matches(ruleSpan('r', ['http.route' => 'api/orders/{order}'])))->toBeFalse()
        ->and($rules->matches(ruleSpan('r', ['http.route' => 'shop', 'plan' => 'pro'])))->toBeFalse();
});

it('drops a rule with an unusable condition instead of widening it, and builds nothing from nothing', function () {
    expect(SpanRules::fromConfig([]))->toBeNull()
        ->and(SpanRules::fromConfig('nope'))->toBeNull()
        ->and(SpanRules::fromConfig([['http.route' => ''], ['plan' => [], 'name' => 'x']]))->toBeNull()
        ->and(SpanRules::fromConfig([['flag' => 'true']])->matches(ruleSpan('s', ['flag' => true])))->toBeTrue();
});
