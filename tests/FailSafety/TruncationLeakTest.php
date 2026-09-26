<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Http\Controllers\SpanIngestController;
use Cbox\Telemetry\Http\Middleware\FlushBrowserIngest;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Testing\CollectingExporter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\MySqlConnection;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Event;

/**
 * Redaction runs at export. Anything that SHORTENS a value before then
 * can remove the very character the patterns match on — the `@` that
 * makes a URL userinfo, the separator that makes a token a token — and
 * what is left reads as ordinary text.
 *
 * This is the same bug three times over: a span-level value cap, a
 * query-text cap, and an ingest cap. The rule that prevents all three
 * is that nothing cuts a value before the redactor has seen it.
 */
beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);
});

function exportedText(CollectingExporter $collector): string
{
    Telemetry::flush();

    $text = '';

    foreach ($collector->batches() as $batch) {
        foreach ($batch->spans as $span) {
            $text .= implode(' ', array_map(strval(...), $span->attributes()));
        }

        foreach ($batch->events as $event) {
            $text .= implode(' ', array_map(strval(...), $event->attributes));
        }
    }

    return $text;
}

it('does not lose a credential in a query comment to the length cap', function () {
    // A connection string in a query comment is not exotic: ORMs and
    // migration tools put them there. Cut at 500 characters, the `@`
    // fell off and the password shipped.
    $connection = new MySqlConnection(static fn () => null, 'shop', '', ['name' => 'mysql', 'driver' => 'mysql']);
    $sql = 'SELECT 1 /* '.str_repeat('x', 467).'https://alice:hunter2@example.test/ */';

    Telemetry::tracer()->startSpan('checkout');
    Event::dispatch(new QueryExecuted($sql, [], 1.0, $connection));

    expect(exportedText($this->collector))->not->toContain('hunter2');
});

it('does not lose a credential in an ingested attribute to the length cap', function () {
    // Inbound browser attributes are cut to 1024 characters, which is
    // the right call for untrusted input and the wrong order: cutting
    // before redaction removes the `@` and the password reads as text.
    $value = str_repeat('x', 1_003).'https://alice:hunter2@example.test/';

    $request = Request::create('/telemetry/spans', 'POST', content: json_encode([
        'spans' => [[
            'traceId' => str_repeat('ab12', 8),
            'spanId' => str_repeat('cd34', 4),
            'name' => 'document.load',
            'kind' => 'client',
            'start' => (int) (microtime(true) * 1000) - 500,
            'end' => (int) (microtime(true) * 1000),
            'attributes' => ['url.full' => $value],
        ]],
    ]));
    $request->headers->set('Content-Type', 'application/json');

    $route = new Route('POST', '/telemetry/spans', []);
    $route->defaults('telemetryIngest', ['enabled' => true, 'max_spans' => 128, 'max_attributes' => 32, 'sample_rate' => 1.0]);
    $request->setRouteResolver(fn () => $route);

    $response = (new SpanIngestController)($request, app(TelemetryManager::class));
    app(FlushBrowserIngest::class)->terminate($request, $response);

    $exported = exportedText($this->collector);

    // The premise: the span arrived and was exported at all.
    expect($exported)->toContain('xxx')
        ->and($exported)->not->toContain('hunter2');
});
