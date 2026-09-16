<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Testing;

use Cbox\Telemetry\Contracts\NativeRuntime;

/**
 * The `cbox_telemetry` extension, faked.
 *
 * Bind it to test what an app does with native measurements on a machine
 * that does not have the extension — which is every CI runner that has not
 * installed it, and most laptops:
 *
 *     $this->app->instance(NativeRuntime::class, FakeNativeRuntime::withProfile());
 *
 * It keeps the one contract detail call sites depend on: `finish()` returns
 * an empty array for any handle that is not the open one, because a unit
 * someone else already closed is not a unit that took 0 ms.
 */
final class FakeNativeRuntime implements NativeRuntime
{
    /** @var list<array<string, scalar>> every context passed to begin() */
    public array $begun = [];

    /** @var list<array{handle: int, profile: bool, stacks: bool}> */
    public array $finished = [];

    /** @var array<string, mixed> what finish() returns for the open handle */
    public array $result;

    /** @var list<array<string, mixed>> */
    public array $crashes = [];

    /** @var array<string, mixed> */
    public array $status = [
        'version' => '0.1.0',
        'enabled' => true,
        // The extension reports these separately for a reason: a unit opens
        // whether or not a sampler exists, so anything deciding which
        // profiler runs has to read profiler_enabled rather than infer it
        // from having been handed a handle.
        'profiler_enabled' => true,
        'profiler_status' => 'ready',
        'timer_backend' => 'posix',
        'timer_cpu_time' => true,
        'crash_recorder' => 'armed',
        'crash_path' => '/tmp/cbox-telemetry/501/crash-4711.bin',
        'auto' => false,
        // No automatic unit waiting to be adopted.
        'unit_handle' => 0,
        'unit_automatic' => false,
        'limits' => ['period_us' => 1000, 'max_depth' => 64],
    ];

    private int $nextHandle = 1;

    private int $open = 0;

    /**
     * @param  array<string, mixed>|null  $result
     */
    public function __construct(
        public bool $available = true,
        ?array $result = null,
    ) {
        $this->result = $result ?? self::plainResult();
    }

    /**
     * A unit that measured a PDO connect and a couple of cURL calls, with
     * no profile — the common case for a request fast enough to discard it.
     *
     * @return array<string, mixed>
     */
    public static function plainResult(): array
    {
        return [
            'duration_ns' => 42_000_000,
            'unit' => 'http',
            'sampled' => true,
            'profiling' => true,
            'automatic' => false,
            'operations' => [
                'pdo.connect' => ['count' => 1, 'total_ns' => 8_300_000, 'max_ns' => 8_300_000],
                'curl.exec' => ['count' => 2, 'total_ns' => 29_000_000, 'max_ns' => 18_000_000],
            ],
            'counters' => [
                'profiler.samples' => 0,
                'profiler.dropped' => 0,
                'profiler.timer_overruns' => 0,
                'profiler.deferred_samples' => 0,
                'gc.runs' => 3,
                'gc.collected' => 128,
                'ops.overflow' => 0,
                'profiler.capped' => false,
            ],
            'profile' => null,
        ];
    }

    /**
     * The same unit, slow enough to have kept its profile.
     */
    public static function withProfile(): self
    {
        $result = self::plainResult();
        $counters = is_array($result['counters']) ? $result['counters'] : [];
        $counters['profiler.samples'] = 814;
        $counters['profiler.dropped'] = 2;
        $counters['profiler.timer_overruns'] = 14;

        $result['duration_ns'] = 1_842_000_000;
        $result['counters'] = $counters;
        $result['profile'] = [
            'sample_count' => 814,
            'period_ns' => 1_000_000,
            'clock' => 'cpu',
            'dropped' => 2,
            'timer_overruns' => 14,
            'deferred_samples' => 0,
            'deferred_events' => 0,
            'max_deferred' => 0,
            'frames' => [
                ['function' => 'App\\Services\\Pricing::calculate', 'file' => '/app/src/Pricing.php', 'line' => 82],
                ['function' => 'PDO::query', 'file' => null, 'line' => 0],
            ],
            'top_functions' => [
                ['frame_id' => 0, 'samples' => 612],
                ['frame_id' => 1, 'samples' => 202],
            ],
            'stacks' => null,
        ];

        return new self(result: $result);
    }

    public function available(): bool
    {
        return $this->available;
    }

    public function version(): ?string
    {
        return $this->available ? '0.1.0' : null;
    }

    public function status(): array
    {
        return $this->available ? $this->status : [];
    }

    public function begin(array $context): int
    {
        if (! $this->available) {
            return 0;
        }

        $this->begun[] = $context;

        return $this->open = $this->nextHandle++;
    }

    public function finish(int $handle, bool $includeProfile = false, bool $includeStacks = false): array
    {
        if (! $this->available || $this->open === 0 || ($handle !== 0 && $handle !== $this->open)) {
            return [];
        }

        $this->finished[] = ['handle' => $handle, 'profile' => $includeProfile, 'stacks' => $includeStacks];
        $this->open = 0;

        $result = $this->result;

        if (! $includeProfile) {
            $result['profile'] = null;
        }

        return $result;
    }

    public function drainCrashes(int $max = 32): array
    {
        $records = array_slice($this->crashes, 0, max(1, $max));

        $this->crashes = array_slice($this->crashes, count($records));

        return array_values($records);
    }
}
