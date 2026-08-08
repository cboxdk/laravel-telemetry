<?php

declare(strict_types=1);

use Cbox\Telemetry\Contracts\Exporter;
use Cbox\Telemetry\Exporters\Otlp\OtlpExporter;
use Cbox\Telemetry\Exporters\Otlp\OtlpTransport;
use Cbox\Telemetry\Exporters\Spool\Spool;
use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Support\ExportResult;
use Cbox\Telemetry\Support\SignalSet;
use Cbox\Telemetry\Support\TelemetryBatch;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Testing\RejectingExporter;
use Cbox\Telemetry\Tests\Support\StubOtlpServer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

final class ThrowingFlushSpool implements Spool
{
    public function push(array $entry): void {}

    public function pop(int $count): array
    {
        throw new RuntimeException('spool backend unreachable');
    }

    public function requeue(array $entries): void {}

    public function size(): int
    {
        return 0;
    }
}

/** An exporter that breaks its contract and throws. */
final class ExplodingExporter implements Exporter
{
    public function name(): string
    {
        return 'exploding';
    }

    public function supports(): SignalSet
    {
        return SignalSet::all();
    }

    public function export(TelemetryBatch $batch): ExportResult
    {
        throw new RuntimeException('exporter blew up');
    }
}

beforeEach(function () {
    OtlpExporter::resetCircuit();

    Telemetry::counter('orders.created')->inc(5);
});

afterEach(function () {
    if (isset($this->server)) {
        $this->server->stop();

        unset($this->server);
    }
});

/**
 * Point the real OTLP exporter at a URL and rebuild everything that
 * caches the endpoint.
 */
function flushAgainst(string $url, float $timeout = 5.0): void
{
    config()->set('telemetry.exporters', ['otlp']);
    config()->set('telemetry.otlp.endpoint', $url);
    config()->set('telemetry.otlp.timeout', $timeout);
    config()->set('telemetry.otlp.connect_timeout', $timeout);

    app()->forgetInstance(OtlpTransport::class);
    app()->forgetInstance(TelemetryManager::class);
}

it('exports metrics to the configured exporters', function () {
    $collector = new CollectingExporter;
    Telemetry::addExporter($collector);

    $this->artisan('telemetry:flush')->assertSuccessful();

    $metrics = collect($collector->batches())->flatMap(fn ($batch) => $batch->metrics);

    expect($metrics->firstWhere(fn ($family) => $family->name() === 'orders.created'))
        ->not->toBeNull();
});

it('optionally wipes the store after flushing', function () {
    $this->artisan('telemetry:flush', ['--wipe' => true])->assertSuccessful();

    expect(Telemetry::collect())->toBeEmpty();
});

it('fails cleanly instead of dumping a stack trace when the spool throws', function () {
    config()->set('telemetry.otlp.spool.enabled', true);
    app()->instance(Spool::class, new ThrowingFlushSpool);

    $this->artisan('telemetry:flush')
        ->expectsOutputToContain('Failed to ship the spool')
        ->assertFailed();
});

it('reports when telemetry is disabled', function () {
    config()->set('telemetry.enabled', false);

    app()->forgetInstance(TelemetryManager::class);

    $this->artisan('telemetry:flush')
        ->expectsOutputToContain('disabled')
        ->assertSuccessful();
});

/*
|--------------------------------------------------------------------------
| A backend that rejects the batch
|--------------------------------------------------------------------------
|
| The reported bug: telemetryd answered 400 to every request and the
| command printed "Flushed 57 metric families" and exited 0. These run
| against a real HTTP endpoint, because that is where the truth was lost.
|
*/

it('reports the exporter, the status and the body when the endpoint rejects the batch', function () {
    $this->server = StubOtlpServer::start(400, '{"code":3,"message":"unknown metric type for orders.created"}');
    flushAgainst($this->server->url());

    // expectsOutputToContain() consumes one buffered line per call, so
    // several substrings on the same line need one combined check.
    expect(Artisan::call('telemetry:flush'))->toBe(1);

    expect(Artisan::output())
        ->toContain('0 of 1 exporter accepted the batch')
        ->toContain('otlp')
        ->toContain('HTTP 400')
        ->toContain('unknown metric type for orders.created');
});

it('exits non-zero when the endpoint rejects the batch', function () {
    $this->server = StubOtlpServer::start(400, '{"message":"nope"}');
    flushAgainst($this->server->url());

    // Cron watches the exit code and nothing else.
    expect($this->artisan('telemetry:flush')->run())->toBe(1);
});

it('logs a rejected batch as well as printing it', function () {
    Log::spy();

    $this->server = StubOtlpServer::start(400, '{"message":"nope"}');
    flushAgainst($this->server->url());

    $this->artisan('telemetry:flush')->assertFailed();

    Log::shouldHaveReceived('error')
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'telemetry:flush')
                && $context['failures'][0]['exporter'] === 'otlp'
                && $context['failures'][0]['status'] === 'failed'
                && str_contains((string) $context['failures'][0]['reason'], 'HTTP 400');
        })
        ->once();
});

it('reports a curl-level failure and exits non-zero', function () {
    // Reserve a port and let it go — nothing is listening on it.
    $server = StubOtlpServer::start();
    $port = $server->port;
    $server->stop();

    flushAgainst("http://127.0.0.1:{$port}", timeout: 0.5);

    expect(Artisan::call('telemetry:flush'))->toBe(1);

    expect(Artisan::output())
        ->toContain('0 of 1 exporter accepted the batch')
        ->toContain('network error');
});

it('still reports success and exits zero when the endpoint accepts the batch', function () {
    $this->server = StubOtlpServer::start(200, '{}');
    flushAgainst($this->server->url());

    expect(Artisan::call('telemetry:flush'))->toBe(0);

    expect(Artisan::output())
        ->toContain('Flushed')
        ->toContain('metric famil')
        ->toContain('to 1 exporter(s)');

    // Not just a green message — the batch really went over the wire.
    $paths = array_column($this->server->requests(), 'path');

    expect($paths)->toContain('/v1/metrics');
});

it('reports partial acceptance honestly instead of flushed-or-failed', function () {
    Telemetry::addExporter(new CollectingExporter);
    Telemetry::addExporter(new CollectingExporter);
    Telemetry::addExporter(new RejectingExporter(
        ExportResult::failed('HTTP 400: {"message":"unknown resource attribute"}'),
        name: 'otlp',
    ));

    expect(Artisan::call('telemetry:flush'))->toBe(1);

    expect(Artisan::output())
        ->toContain('2 of 3 exporters accepted the batch')
        ->toContain('unknown resource attribute');
});

it('keeps exporting to the other exporters when one throws', function () {
    Telemetry::addExporter(new ExplodingExporter);
    Telemetry::addExporter($collector = new CollectingExporter);

    expect(Artisan::call('telemetry:flush'))->toBe(1);

    expect(Artisan::output())
        ->toContain('1 of 2 exporters accepted the batch')
        ->toContain('the exporter threw');

    // A failing export must not kill the process before the rest run.
    expect($collector->batches())->not->toBeEmpty();
});

it('reports the data points a backend accepted the batch but refused', function () {
    $this->server = StubOtlpServer::start(200, '{"partialSuccess":{"rejectedDataPoints":"4","errorMessage":"out-of-order sample"}}');
    flushAgainst($this->server->url());

    expect(Artisan::call('telemetry:flush'))->toBe(1);

    expect(Artisan::output())
        ->toContain('the backend rejected 4 data point(s)')
        ->toContain('out-of-order sample');
});

it('says so plainly when there are no metrics to flush', function () {
    Telemetry::registry()->store()->wipe();

    $this->artisan('telemetry:flush')
        ->expectsOutputToContain('No metrics to flush')
        ->assertSuccessful();
});
