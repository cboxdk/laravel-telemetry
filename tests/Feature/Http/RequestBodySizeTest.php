<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Http\Middleware\TraceRequest;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanKind;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A request with no Content-Length is a chunked or streamed upload —
 * which is to say, the one case where the body may be arbitrarily
 * large. Measuring it by reading it put that whole body in the memory
 * of a live request, to fill in one number on a span.
 */
final class StreamedUploadRequest extends Request
{
    public int $bodyReads = 0;

    public function getContent(bool $asResource = false)
    {
        $this->bodyReads++;

        return parent::getContent($asResource);
    }
}

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);
});

function bodySizeSpan(CollectingExporter $collector): ?Span
{
    foreach ($collector->batches() as $batch) {
        foreach ($batch->spans as $span) {
            if ($span->kind === SpanKind::Server) {
                return $span;
            }
        }
    }

    return null;
}

it('never reads the request body to measure it', function () {
    $request = StreamedUploadRequest::create('/uploads', 'POST');
    $request->headers->remove('Content-Length');
    $request->server->remove('CONTENT_LENGTH');

    $middleware = app(TraceRequest::class);
    $response = new Response('ok');

    $middleware->handle($request, static fn (): Response => $response);
    $middleware->terminate($request, $response);

    $span = bodySizeSpan($this->collector);

    expect($request->bodyReads)->toBe(0)
        ->and($span)->not->toBeNull()
        ->and($span?->attributes())->not->toHaveKey('http.request.body.size');
});

it('reports the size the client declared', function () {
    $request = Request::create('/uploads', 'POST', content: str_repeat('a', 2048));
    $request->headers->set('Content-Length', '2048');

    $middleware = app(TraceRequest::class);
    $response = new Response('ok');

    $middleware->handle($request, static fn (): Response => $response);
    $middleware->terminate($request, $response);

    expect(bodySizeSpan($this->collector)?->attributes()['http.request.body.size'] ?? null)->toBe(2048);
});
