<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Http\Controllers\SpanIngestController;
use Cbox\Telemetry\Http\Middleware\FlushBrowserIngest;
use Cbox\Telemetry\Support\CampaignAttribution;
use Cbox\Telemetry\Support\ExceptionAttributes;
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

it('does not lose a credential to any of the other length caps', function (int $pad, Closure $record) {
    // Padded so `https://alice:hunter2` ends exactly at the cap that
    // used to be here and the `@` falls on the far side. A shorter or
    // longer value proves nothing: too short and nothing is cut, too
    // long and the secret is cut away with the rest.
    $record(str_repeat('x', $pad).'https://alice:hunter2@example.test/');

    expect(exportedText($this->collector))->not->toContain('hunter2');
})->with([
    // The old CampaignAttribution cap was 1024.
    'campaign parameter' => [1_024 - 21, function (string $value): void {
        $span = Telemetry::tracer()->startSpan('page');
        $span->setAttributes(CampaignAttribution::fromUrl('https://app.test/?utm_source='.rawurlencode($value)));
        $span->end();
    }],
]);

it('does not lose a credential in a browser span name', function () {
    $name = str_repeat('x', 234).'https://alice:hunter2@example.test/';

    $request = Request::create('/telemetry/spans', 'POST', content: json_encode([
        'spans' => [[
            'traceId' => str_repeat('ab12', 8),
            'spanId' => str_repeat('cd34', 4),
            'name' => $name,
            'kind' => 'client',
            'start' => (int) (microtime(true) * 1000) - 500,
            'end' => (int) (microtime(true) * 1000),
            'attributes' => [],
        ]],
    ]));
    $request->headers->set('Content-Type', 'application/json');

    $route = new Route('POST', '/telemetry/spans', []);
    $route->defaults('telemetryIngest', ['enabled' => true, 'max_spans' => 128, 'max_attributes' => 32, 'sample_rate' => 1.0]);
    $request->setRouteResolver(fn () => $route);

    $response = (new SpanIngestController)($request, app(TelemetryManager::class));
    app(FlushBrowserIngest::class)->terminate($request, $response);

    Telemetry::flush();

    $names = collect($this->collector->batches())->flatMap(fn ($b) => $b->spans)->map(fn ($s) => $s->name)->implode(' ');

    expect($names)->toContain('xxx')
        ->and($names)->not->toContain('hunter2');
});

it('hands the exception text over whole, for the redactor to cut', function () {
    // The stack trace and the source window were cut at 12000 and 4000
    // bytes here, before redaction could read them — and with argument
    // capture on, a frame carries whatever was passed to it. The cut
    // only leaks when an operator has raised max_value_length above
    // it, which is exactly the operator who wanted the whole trace.
    $deep = static function (int $n, string $secret) use (&$deep): Throwable {
        return $n > 0 ? $deep($n - 1, $secret) : new RuntimeException('failed');
    };

    $attributes = ExceptionAttributes::from($deep(60, 'x'));
    $trace = (string) $attributes['exception.stacktrace'];

    // Whole: the last frame is present, which a 12000-byte cut removed
    // on any trace deeper than a page or two.
    expect(strlen($trace))->toBeGreaterThan(2_000)
        ->and($trace)->toContain('{main}');
});
