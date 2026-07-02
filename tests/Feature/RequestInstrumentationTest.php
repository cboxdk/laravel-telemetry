<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\SpanStatus;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);

    Route::get('/users/{id}', fn (string $id) => ['id' => $id]);
});

function requestSpans(CollectingExporter $collector): array
{
    $spans = [];

    foreach ($collector->batches() as $batch) {
        foreach ($batch->spans as $span) {
            if ($span->kind === SpanKind::Server) {
                $spans[] = $span;
            }
        }
    }

    return $spans;
}

it('wraps requests in a server span named after the route pattern', function () {
    $this->get('/users/7')->assertOk();

    $spans = requestSpans($this->collector);

    expect($spans)->toHaveCount(1)
        ->and($spans[0]->name)->toBe('GET /users/{id}')
        ->and($spans[0]->attributes()['http.route'])->toBe('/users/{id}')
        ->and($spans[0]->attributes()['http.response.status_code'])->toBe(200)
        ->and($spans[0]->status())->toBe(SpanStatus::Ok);
});

it('records the request duration histogram with low-cardinality labels', function () {
    $this->get('/users/7');
    $this->get('/users/8');

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());

    $sample = $families['http.server.request.duration']->samples[0];

    expect($sample->count)->toBe(2)
        ->and($sample->labels['http.route'])->toBe('/users/{id}')
        ->and($sample->labels['http.response.status_code'])->toBe('200');
});

it('continues an incoming traceparent as a child span', function () {
    $this->get('/users/7', [
        'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
    ]);

    $span = requestSpans($this->collector)[0];

    expect($span->traceId)->toBe('0af7651916cd43dd8448eb211c80319c')
        ->and($span->parentSpanId)->toBe('b7ad6b7169203331');
});

it('does not export when the incoming trace is not sampled', function () {
    $this->get('/users/7', [
        'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-00',
    ]);

    expect(requestSpans($this->collector))->toBeEmpty();
});

it('attributes the request span to the authenticated user', function () {
    $this->actingAs(new GenericUser(['id' => 42]));

    $this->get('/users/7');

    $span = requestSpans($this->collector)[0];

    expect($span->attributes()['enduser.id'])->toBe('42');
});

it('omits user attribution when disabled', function () {
    config()->set('telemetry.instrument.user', false);

    $this->actingAs(new GenericUser(['id' => 42]));

    $this->get('/users/7');

    expect(requestSpans($this->collector)[0]->attributes())->not->toHaveKey('enduser.id');
});

it('marks 5xx responses as errors', function () {
    Route::get('/broken', fn () => throw new RuntimeException('boom'));

    $this->get('/broken');

    $span = collect(requestSpans($this->collector))->firstWhere('name', 'GET /broken');

    expect($span->status())->toBe(SpanStatus::Error)
        ->and($span->attributes()['http.response.status_code'])->toBe(500);
});

it('exposes the trace id in a response header by default', function () {
    $response = $this->get('/users/7');

    $span = requestSpans($this->collector)[0];

    expect($response->headers->get('X-Trace-Id'))->toBe($span->traceId);
});

it('supports a custom header name and disabling it', function () {
    config()->set('telemetry.traces.response_header', 'X-Request-Ref');

    expect($this->get('/users/7')->headers->get('X-Request-Ref'))->toMatch('/^[0-9a-f]{32}$/');

    config()->set('telemetry.traces.response_header', null);

    $response = $this->get('/users/7');

    expect($response->headers->has('X-Trace-Id'))->toBeFalse()
        ->and($response->headers->has('X-Request-Ref'))->toBeFalse();
});

it('publishes the trace id to Laravel Context for error trackers and logs', function () {
    $this->get('/users/7');

    $span = requestSpans($this->collector)[0];

    expect(Context::get('trace_id'))->toBe($span->traceId);
});

it('does not touch Laravel Context when sharing is disabled', function () {
    config()->set('telemetry.traces.share_context', false);

    $this->get('/users/7');

    expect(Context::get('trace_id'))->toBeNull();
});
