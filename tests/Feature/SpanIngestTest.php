<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Http\Controllers\SpanIngestController;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\SpanKind;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);
});

function ingest(array $spans, array $config = []): Response
{
    $request = Request::create('/telemetry/spans', 'POST', content: json_encode(['spans' => $spans]));
    $request->headers->set('Content-Type', 'application/json');

    $route = new Route('POST', '/telemetry/spans', []);
    $route->defaults('telemetryIngest', $config + ['enabled' => true, 'max_spans' => 128, 'max_attributes' => 32, 'sample_rate' => 1.0]);
    $request->setRouteResolver(fn () => $route);

    return (new SpanIngestController)($request, app(TelemetryManager::class));
}

function ingestedSpans(CollectingExporter $c): array
{
    return collect($c->batches())->flatMap(fn ($b) => $b->spans)->all();
}

function browserSpan(array $over = []): array
{
    $now = (int) (microtime(true) * 1000);

    return array_merge([
        'traceId' => str_repeat('ab12', 8),
        'spanId' => str_repeat('cd34', 4),
        'name' => 'document.load',
        'kind' => 'client',
        'start' => $now - 500,
        'end' => $now,
        'attributes' => ['http.url' => 'https://app.test/dashboard'],
    ], $over);
}

it('ingests a valid browser span under its own trace id', function () {
    $trace = str_repeat('ab12', 8);
    $response = ingest([browserSpan(['traceId' => $trace])]);

    expect($response->getStatusCode())->toBe(204);

    $spans = ingestedSpans($this->collector);
    expect($spans)->toHaveCount(1)
        ->and($spans[0]->traceId)->toBe($trace)
        ->and($spans[0]->name)->toBe('document.load')
        ->and($spans[0]->kind)->toBe(SpanKind::Client)
        ->and($spans[0]->attributes()['browser'])->toBeTrue()
        ->and($spans[0]->attributes()['http.url'])->toBe('https://app.test/dashboard')
        ->and($spans[0]->durationMs())->toBeGreaterThan(0);
});

it('drops invalid spans but keeps the valid ones in the same batch', function () {
    ingest([
        browserSpan(['traceId' => 'not-hex']),                 // bad trace id
        browserSpan(['spanId' => 'short']),                    // bad span id
        browserSpan(['start' => 0, 'end' => 0]),               // clock-skewed / out of window
        browserSpan(['name' => 'ok.span']),                    // valid
    ]);

    $spans = ingestedSpans($this->collector);
    expect($spans)->toHaveCount(1)->and($spans[0]->name)->toBe('ok.span');
});

it('caps the number of spans and the attributes per span', function () {
    $many = array_fill(0, 500, browserSpan());
    ingest($many, ['max_spans' => 10]);
    expect(ingestedSpans($this->collector))->toHaveCount(10);

    $bigAttrs = [];
    for ($i = 0; $i < 100; $i++) {
        $bigAttrs["k$i"] = "v$i";
    }
    $fresh = new CollectingExporter;
    Telemetry::addExporter($fresh);
    ingest([browserSpan(['attributes' => $bigAttrs])], ['max_attributes' => 5]);

    $attrs = collect($fresh->batches())->flatMap(fn ($b) => $b->spans)->last()->attributes();
    // 5 capped attributes + the stamped 'browser' flag.
    expect(count($attrs))->toBeLessThanOrEqual(6);
});

it('redacts secrets in browser span attributes before export', function () {
    ingest([browserSpan(['attributes' => ['api_key' => 'abc123', 'note' => 'header was Bearer abcdef1234567890abcdef']])]);

    $attrs = ingestedSpans($this->collector)[0]->attributes();
    expect($attrs['api_key'])->toBe('[REDACTED]')
        ->and($attrs['note'])->toBe('header was Bearer [REDACTED]');
});

it('404s when the ingest is disabled', function () {
    expect(fn () => ingest([browserSpan()], ['enabled' => false]))
        ->toThrow(HttpException::class);
});
