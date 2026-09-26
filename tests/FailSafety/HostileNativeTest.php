<?php

declare(strict_types=1);

use Cbox\Telemetry\Contracts\NativeRuntime;
use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Support\FailSafe;
use Illuminate\Support\Facades\Route;

/**
 * The extension ships and upgrades separately from this package, and it
 * is a PHP extension — the one dependency that can take a process down
 * rather than throw. It is also, per the base images, a thing an
 * operator can switch on without telling anybody.
 *
 * So the package is tested against three versions of it that do not
 * exist: one that throws from every call, one that answers with the
 * wrong shapes, and one that claims to be there and is not.
 */
function hostileRuntime(Closure $behaviour): NativeRuntime
{
    return new class($behaviour) implements NativeRuntime
    {
        public function __construct(private Closure $behaviour) {}

        public function available(): bool
        {
            return true;
        }

        public function version(): ?string
        {
            return ($this->behaviour)('version');
        }

        public function status(): array
        {
            return ($this->behaviour)('status');
        }

        public function begin(array $context): int
        {
            return ($this->behaviour)('begin');
        }

        public function finish(int $handle, bool $includeProfile = false, bool $includeStacks = false): array
        {
            return ($this->behaviour)('finish');
        }

        public function drainCrashes(int $max = 32): array
        {
            return ($this->behaviour)('drainCrashes');
        }
    };
}

function withRuntime(NativeRuntime $runtime): void
{
    config()->set('telemetry.native.enabled', true);
    app()->instance(NativeRuntime::class, $runtime);
}

it('serves the request when every native call throws', function (): void {
    withRuntime(hostileRuntime(static fn (string $call) => throw new RuntimeException("the extension crashed in {$call}")));

    Route::middleware('web')->get('/orders', fn () => 'ok');

    $caught = [];
    FailSafe::handleExceptionsUsing(static function (Throwable $e) use (&$caught): void {
        $caught[] = $e;
    });

    try {
        $this->get('/orders')->assertOk()->assertSee('ok');
    } finally {
        FailSafe::handleExceptionsUsing(null);
    }
});

it('records nothing rather than nonsense when the shapes are wrong', function (): void {
    // A version whose return shape this package was not written
    // against. Every field is the wrong type; none of it may reach a
    // metric, and a duration of "quite a while" must not become 0 ms.
    Telemetry::fake();

    withRuntime(hostileRuntime(static fn (string $call): mixed => match ($call) {
        'begin' => 1,
        'finish' => ['duration_ns' => 'quite a while', 'operations' => 'lots', 'counters' => 42, 'profile' => true],
        'drainCrashes' => ['not a record', 7],
        'status' => ['ok'],
        default => null,
    }));

    Route::middleware('web')->get('/orders', fn () => 'ok');

    $this->get('/orders')->assertOk();

    Telemetry::assertCounterNotIncremented('runtime.operations');
    Telemetry::assertHistogramNotRecorded('runtime.operation.duration');
});

it('serves the request when the extension is simply absent', function (): void {
    // The ordinary case, and the one that must cost nothing: no
    // extension, no runtime, no branch taken.
    config()->set('telemetry.native.enabled', true);
    app()->forgetInstance(NativeRuntime::class);

    Route::middleware('web')->get('/orders', fn () => 'ok');

    $this->get('/orders')->assertOk()->assertSee('ok');
});
