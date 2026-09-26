<?php

declare(strict_types=1);

use Cbox\Telemetry\Exporters\Otlp\OtlpTransport;
use Cbox\Telemetry\Tests\Support\StubOtlpServer;

afterEach(function () {
    if (isset($this->server)) {
        $this->server->stop();

        unset($this->server);
    }
});

function transportFor(StubOtlpServer $server, bool $compress = true): OtlpTransport
{
    return new OtlpTransport(
        endpoint: $server->url(),
        timeout: 5.0,
        connectTimeout: 2.0,
        compress: $compress,
    );
}

it('reports a 2xx as a success', function () {
    $this->server = StubOtlpServer::start(200, '{"partialSuccess":{}}');

    $result = transportFor($this->server)->post('/v1/metrics', ['resourceMetrics' => []]);

    expect($result->success)->toBeTrue()
        ->and($result->rejected)->toBe(0)
        ->and($result->reason)->toBeNull();
});

it('reports a rejected batch as a permanent failure carrying the status and the response body', function () {
    // What telemetryd actually answers with — the diagnosis is in the body.
    $this->server = StubOtlpServer::start(400, '{"code":3,"message":"invalid metric name: orders.created!"}');

    $result = transportFor($this->server)->post('/v1/metrics', ['resourceMetrics' => []]);

    expect($result->success)->toBeFalse()
        ->and($result->retryable)->toBeFalse()
        ->and($result->reason)->toContain('HTTP 400')
        ->and($result->reason)->toContain('invalid metric name: orders.created!');
});

it('keeps the body on a retryable status too', function () {
    $this->server = StubOtlpServer::start(503, '{"message":"ingester unavailable"}');

    $result = transportFor($this->server)->post('/v1/traces', ['resourceSpans' => []]);

    expect($result->success)->toBeFalse()
        ->and($result->retryable)->toBeTrue()
        ->and($result->reason)->toContain('HTTP 503')
        ->and($result->reason)->toContain('ingester unavailable');
});

it('collapses and truncates a long error body instead of spraying the console', function () {
    // A proxy in front of the collector answering with an HTML page.
    $this->server = StubOtlpServer::start(413, "<html>\n<body>\n".str_repeat('payload too large ', 200)."\n</body>\n</html>");

    $result = transportFor($this->server)->post('/v1/traces', ['resourceSpans' => []]);

    expect($result->success)->toBeFalse()
        ->and($result->reason)->not->toBeNull()
        ->and($result->reason)->not->toContain("\n")
        ->and($result->reason)->toContain('… (truncated)')
        ->and(mb_strlen((string) $result->reason))->toBeLessThan(560);
});

it('reports a curl-level failure as retryable, naming the error', function () {
    // Nothing is listening: the request never reaches an HTTP status.
    $transport = new OtlpTransport(
        endpoint: 'http://127.0.0.1:'.freeLoopbackPort(),
        timeout: 1.0,
        connectTimeout: 0.5,
    );

    $result = $transport->post('/v1/metrics', ['resourceMetrics' => []]);

    expect($result->success)->toBeFalse()
        ->and($result->retryable)->toBeTrue()
        ->and($result->reason)->toContain('network error');
});

it('reports OTLP partial success as accepted-with-rejections', function () {
    $this->server = StubOtlpServer::start(200, '{"partialSuccess":{"rejectedDataPoints":"7","errorMessage":"stale samples"}}');

    $result = transportFor($this->server)->post('/v1/metrics', ['resourceMetrics' => []]);

    expect($result->success)->toBeTrue()
        ->and($result->rejected)->toBe(7)
        ->and($result->reason)->toBe('stale samples');
});

it('gzips a batch over the threshold and the backend gets the same JSON back', function () {
    $this->server = StubOtlpServer::start(200, '{}');

    $payload = ['resourceSpans' => [['note' => str_repeat('a', OtlpTransport::COMPRESSION_THRESHOLD * 2)]]];

    expect(transportFor($this->server)->post('/v1/traces', $payload)->success)->toBeTrue();

    $request = $this->server->requests()[0];

    expect($request['encoding'])->toBe('gzip')
        ->and($request['bytes'])->toBeLessThan(OtlpTransport::COMPRESSION_THRESHOLD * 2)
        ->and(json_decode($request['body'], true))->toBe($payload);
});

it('sends small batches uncompressed', function () {
    $this->server = StubOtlpServer::start(200, '{}');

    transportFor($this->server)->post('/v1/traces', ['resourceSpans' => []]);

    expect($this->server->requests()[0]['encoding'])->toBe('');
});

it('does not buffer an endpoint that answers with a gigabyte', function () {
    // Not a collector: a proxy error page, a captive portal, an
    // endpoint pointed at a file server. Whatever it is, the response
    // lands in the memory of a live application request, and the only
    // thing read out of it is an error message.
    $this->server = StubOtlpServer::start(500, str_repeat('x', 4 * 1024 * 1024));

    // The peak, not the difference: the whole response is freed when
    // post() returns, so before/after would measure nothing at all and
    // pass against the unbounded version.
    $before = memory_get_usage();
    memory_reset_peak_usage();

    $result = transportFor($this->server)->post('/v1/traces', ['resourceSpans' => []]);

    expect($result->success)->toBeFalse()
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(1024 * 1024)
        ->and($result->reason)->toContain('HTTP 500');
});

it('does not read a truncated 200 as a clean accept', function () {
    // OTLP reports rejections INSIDE a 200. A response too large to
    // read does not decode, and "does not decode" must not become
    // "accepted everything" — that is the silent partial loss these
    // tests exist for.
    $body = json_encode([
        'partialSuccess' => [
            'rejectedSpans' => '7',
            'errorMessage' => str_repeat('x', 200_000),
        ],
    ], JSON_THROW_ON_ERROR);

    $this->server = StubOtlpServer::start(200, $body);

    $result = transportFor($this->server)->post('/v1/traces', ['resourceSpans' => []]);

    expect($result->success)->toBeTrue()
        ->and($result->reason)->toContain('too large to read');
});

it('still reads a partial success that fits', function () {
    $this->server = StubOtlpServer::start(200, json_encode([
        'partialSuccess' => ['rejectedSpans' => '7', 'errorMessage' => 'bad resource'],
    ], JSON_THROW_ON_ERROR));

    $result = transportFor($this->server)->post('/v1/traces', ['resourceSpans' => []]);

    expect($result->rejected)->toBe(7)
        ->and($result->reason)->toBe('bad resource');
});

function freeLoopbackPort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $name = (string) stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr($name, (int) strrpos($name, ':') + 1);
}

it('reads Retry-After in both forms RFC 9110 allows', function (string $header, int $atLeast, int $atMost) {
    $transport = new OtlpTransport('http://unused:4318');
    $method = new ReflectionMethod(OtlpTransport::class, 'retryAfter');

    $seconds = $method->invoke($transport, "HTTP/1.1 503 Service Unavailable\r\nRetry-After: {$header}\r\n\r\n");

    expect($seconds)->toBeGreaterThanOrEqual($atLeast)
        ->and($seconds)->toBeLessThanOrEqual($atMost);
})->with([
    // Delay-seconds: what a collector sends.
    'seconds' => ['120', 120, 120],
    // HTTP-date: what a proxy in front of one sends. Ignoring it fell
    // back to the default cooldown, which is wrong in both directions.
    'http date' => [gmdate('D, d M Y H:i:s \G\M\T', time() + 300), 295, 300],
    'http date in the past' => [gmdate('D, d M Y H:i:s \G\M\T', time() - 300), 0, 0],
]);

it('ignores a Retry-After it cannot make sense of', function () {
    $transport = new OtlpTransport('http://unused:4318');
    $method = new ReflectionMethod(OtlpTransport::class, 'retryAfter');

    expect($method->invoke($transport, "HTTP/1.1 503 x\r\nRetry-After: soon please\r\n\r\n"))->toBeNull();
});
