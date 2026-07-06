<?php

declare(strict_types=1);
use Cbox\Telemetry\Exporters\Spool\Spool;
use Cbox\Telemetry\TelemetryManager;
use Illuminate\Support\Facades\Artisan;

final class FakeDoctorSpool implements Spool
{
    public function __construct(private readonly int $depth) {}

    public function push(array $entry): void {}

    public function pop(int $count): array
    {
        return [];
    }

    public function requeue(array $entries): void {}

    public function size(): int
    {
        return $this->depth;
    }
}

it('passes with the array store and no exporters', function () {
    $this->artisan('telemetry:doctor')
        ->expectsOutputToContain('Metric store [array]')
        ->expectsOutputToContain('All checks passed')
        ->assertSuccessful();
});

it('warns about an open prometheus endpoint in local/testing', function () {
    config()->set('telemetry.prometheus.allowed_ips', []);

    $this->artisan('telemetry:doctor')
        ->expectsOutputToContain('OPEN — no allowlist or token, but running in testing')
        ->assertSuccessful();
});

it('reports a closed prometheus endpoint outside local/testing', function () {
    config()->set('telemetry.prometheus.allowed_ips', []);
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('telemetry:doctor')
        ->expectsOutputToContain('CLOSED — no TELEMETRY_ALLOWED_IPS or TELEMETRY_PROMETHEUS_TOKEN')
        ->assertSuccessful();
});

it('reports an ok prometheus endpoint when a token is configured', function () {
    config()->set('telemetry.prometheus.allowed_ips', []);
    config()->set('telemetry.prometheus.token', 'secret-token');

    $this->artisan('telemetry:doctor')
        ->expectsOutputToContain('OK — bearer token configured')
        ->assertSuccessful();
});

it('warns when the apcu store shares the same segment as the apcu cache driver', function () {
    config()->set('telemetry.store', 'apcu');
    config()->set('cache.default', 'apcu');
    config()->set('cache.stores.apcu.driver', 'apcu');

    $this->artisan('telemetry:doctor')
        ->expectsOutputToContain('apcu_clear_cache() (via `cache:clear`) wipes the WHOLE APCu segment')
        ->assertSuccessful();
});

it('warns when telemetry and the cache store share the same redis connection', function () {
    config()->set('telemetry.store', 'redis');
    config()->set('telemetry.stores.redis.connection', 'default');
    config()->set('cache.default', 'redis');
    config()->set('cache.stores.redis.driver', 'redis');
    config()->set('cache.stores.redis.connection', 'default');
    config()->set('database.redis.default', ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0]);

    $this->artisan('telemetry:doctor')
        ->expectsOutputToContain('share the same Redis database')
        ->assertSuccessful();
});

it('stays quiet when telemetry uses a dedicated redis connection', function () {
    config()->set('telemetry.store', 'redis');
    config()->set('telemetry.stores.redis.connection', 'telemetry');
    config()->set('cache.default', 'redis');
    config()->set('cache.stores.redis.driver', 'redis');
    config()->set('cache.stores.redis.connection', 'default');
    config()->set('database.redis.default', ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0]);
    config()->set('database.redis.telemetry', ['host' => '127.0.0.1', 'port' => 6379, 'database' => 5]);

    $this->artisan('telemetry:doctor')
        ->doesntExpectOutputToContain('Cache collision')
        ->assertSuccessful();
});

it('reports profiling status based on extension availability', function () {
    $expected = extension_loaded('excimer') ? 'OK — ext-excimer loaded' : 'off — ext-excimer not installed';

    $this->artisan('telemetry:doctor')
        ->expectsOutputToContain($expected)
        ->assertSuccessful();
});

it('reports profiling as disabled when turned off in config', function () {
    config()->set('telemetry.instrument.profiling', false);

    // expectsOutputToContain() consumes one buffered line per call, so a
    // single line with both substrings needs one combined check, not two.
    $this->artisan('telemetry:doctor')
        ->expectsOutputToContain('CPU profiling')
        ->assertSuccessful();

    Artisan::call('telemetry:doctor');

    expect(Artisan::output())->toContain('CPU profiling')->toContain('disabled');
});

it('reports the spool as disabled when not enabled', function () {
    $this->artisan('telemetry:doctor')
        ->expectsOutputToContain('disabled')
        ->assertSuccessful();
});

it('reports a healthy spool depth', function () {
    config()->set('telemetry.otlp.spool.enabled', true);
    config()->set('telemetry.otlp.spool.max_items', 20000);
    app()->instance(Spool::class, new FakeDoctorSpool(10));

    $this->artisan('telemetry:doctor')
        ->expectsOutputToContain('OK — 10/20000')
        ->assertSuccessful();
});

it('warns when the spool is over half full', function () {
    config()->set('telemetry.otlp.spool.enabled', true);
    config()->set('telemetry.otlp.spool.max_items', 100);
    app()->instance(Spool::class, new FakeDoctorSpool(60));

    Artisan::call('telemetry:doctor');

    expect(Artisan::output())
        ->toContain('60/100')
        ->toContain('over half full');
});

it('fails the check when the spool is near capacity — dropping entries', function () {
    config()->set('telemetry.otlp.spool.enabled', true);
    config()->set('telemetry.otlp.spool.max_items', 100);
    app()->instance(Spool::class, new FakeDoctorSpool(95));

    $this->artisan('telemetry:doctor')
        ->expectsOutputToContain('near capacity')
        ->assertFailed();
});

it('reports when telemetry is disabled', function () {
    config()->set('telemetry.enabled', false);

    app()->forgetInstance(TelemetryManager::class);

    $this->artisan('telemetry:doctor')
        ->expectsOutputToContain('DISABLED')
        ->assertSuccessful();
});
