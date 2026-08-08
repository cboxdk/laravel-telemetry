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

function freeLoopbackPort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $name = (string) stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr($name, (int) strrpos($name, ':') + 1);
}
