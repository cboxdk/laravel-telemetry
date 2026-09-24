<?php

declare(strict_types=1);

use Cbox\Telemetry\Contracts\NativeRuntime;
use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Testing\FakeNativeRuntime;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanKind;
use Illuminate\Support\Facades\Route;

/**
 * A runtime whose startup is slow, standing in for the real cost of
 * arming the instrument — on macOS the resource sample alone shells out
 * to lsof and takes ~30ms.
 */
final class SlowToArmRuntime implements NativeRuntime
{
    public function __construct(private readonly NativeRuntime $inner = new FakeNativeRuntime) {}

    public static int $begun = 0;

    public function begin(array $context): int
    {
        self::$begun++;
        usleep(80_000);

        return $this->inner->begin($context);
    }

    public function available(): bool
    {
        return $this->inner->available();
    }

    public function version(): ?string
    {
        return $this->inner->version();
    }

    public function status(): array
    {
        return $this->inner->status();
    }

    public function finish(int $handle, bool $includeProfile = false, bool $includeStacks = false): array
    {
        return $this->inner->finish($handle, $includeProfile, $includeStacks);
    }

    public function drainCrashes(int $max = 32): array
    {
        return $this->inner->drainCrashes($max);
    }
}

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);

    // TestCase disables the native runtime for the suite; this case needs
    // the seam it gates, because that is where the slow startup is injected.
    config()->set('telemetry.native.enabled', true);

    SlowToArmRuntime::$begun = 0;
    $this->app->instance(NativeRuntime::class, new SlowToArmRuntime);

    Route::get('/attribution', fn () => 'ok');
});

it('does not charge the instrument\'s own startup to the application', function () {
    $this->get('/attribution')->assertOk();

    Telemetry::flush();

    $spans = [];

    foreach ($this->collector->batches() as $batch) {
        foreach ($batch->spans as $span) {
            $spans[] = $span;
        }
    }

    $server = collect($spans)->firstWhere('kind', SpanKind::Server);

    assert($server instanceof Span);

    // The 40ms above is this middleware arming itself, not the app
    // resolving a route. Charged to laravel.routing it reads as the
    // application's route table being slow, which is the wrong thing to
    // go and optimise.
    expect(SlowToArmRuntime::$begun)->toBeGreaterThan(0, 'the slow startup must actually run, or this test proves nothing');

    expect($server->attributes()['laravel.routing_ms'])->toBeLessThan(35.0);
});
