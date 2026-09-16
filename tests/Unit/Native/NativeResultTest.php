<?php

declare(strict_types=1);

use Cbox\Telemetry\Native\NativeResult;
use Cbox\Telemetry\Testing\FakeNativeRuntime;

it('reads a unit result in the units the rest of the package speaks', function () {
    $result = NativeResult::fromArray(FakeNativeRuntime::plainResult());

    expect($result)->not->toBeNull()
        ->and($result->durationMs)->toBe(42.0)
        ->and($result->unit)->toBe('http')
        ->and($result->operations['pdo.connect'])->toBe(['count' => 1, 'total_ms' => 8.3, 'max_ms' => 8.3])
        ->and($result->operations['curl.exec']['count'])->toBe(2)
        ->and($result->counter('gc.runs'))->toBe(3)
        ->and($result->profile)->toBeNull();
});

/**
 * An unknown handle comes back as an empty array — a unit someone else
 * already closed, or one a nested begin() abandoned. Reporting that as a
 * unit of work that took 0 ms would put a lie in every dashboard.
 */
it('is null for the empty array an unknown handle returns', function () {
    expect(NativeResult::fromArray([]))->toBeNull()
        ->and(NativeResult::fromArray(null))->toBeNull()
        ->and(NativeResult::fromArray(['unit' => 'http']))->toBeNull();
});

it('survives a result shape it does not recognise', function () {
    $result = NativeResult::fromArray([
        'duration_ns' => '12000000',
        'unit' => ['nonsense'],
        'operations' => 'not an array',
        'counters' => ['gc.runs' => 'three', 'gc.collected' => 4],
        'profile' => 'not a profile',
    ]);

    expect($result)->not->toBeNull()
        ->and($result->durationMs)->toBe(12.0)
        ->and($result->unit)->toBe('other')
        ->and($result->operations)->toBe([])
        ->and($result->counter('gc.runs'))->toBe(0)
        ->and($result->counter('gc.collected'))->toBe(4)
        ->and($result->profile)->toBeNull();
});

it('drops operations that recorded no calls', function () {
    $result = NativeResult::fromArray([
        'duration_ns' => 1_000_000,
        'operations' => ['redis.connect' => ['count' => 0, 'total_ns' => 0, 'max_ns' => 0]],
    ]);

    expect($result?->operations)->toBe([]);
});

/**
 * Frames are emitted once and referred to by id afterwards. That is the
 * right shape on the wire and the wrong shape for an event attribute, so
 * the ids are resolved at this boundary and nowhere else.
 */
it('resolves frame ids into function names', function () {
    $runtime = FakeNativeRuntime::withProfile();
    $result = NativeResult::fromArray($runtime->result);

    expect($result?->profile?->topFunctions)->toBe([
        ['function' => 'App\\Services\\Pricing::calculate', 'file' => '/app/src/Pricing.php', 'line' => 82, 'samples' => 612],
        ['function' => 'PDO::query', 'samples' => 202],
    ]);
});

it('keeps only the requested number of top functions', function () {
    $runtime = FakeNativeRuntime::withProfile();

    expect(NativeResult::fromArray($runtime->result, topFunctions: 1)?->profile?->topFunctions)->toHaveCount(1);
});

/**
 * dropped + timer_overruns are the difference between "this is where the
 * time went" and "this is arithmetic", so they travel with the profile.
 */
it('reports how much of a profile was actually sampled', function () {
    $profile = NativeResult::fromArray(FakeNativeRuntime::withProfile()->result)?->profile;

    // Accounted ticks are sample_count + dropped; of those, the dropped
    // and the never-delivered are not observations of anything.
    expect($profile?->sampleCount)->toBe(814)
        ->and($profile?->dropped)->toBe(2)
        ->and($profile?->timerOverruns)->toBe(14)
        ->and($profile?->confidence())->toBe(round(800 / 816, 4));
});

/**
 * A sample carries the WEIGHT of every tick it accounts for, overruns
 * included — `sample_count` is ticks, not stack walks. Reading it as
 * "samples we got" and adding the overruns as "samples we missed" both
 * inflates the numerator and double-counts the denominator, and reported
 * 57% for a profile that observed 25% of its ticks.
 */
it('does not count an overrun tick as a sample of anything', function () {
    $raw = FakeNativeRuntime::withProfile()->result;
    $raw['profile']['sample_count'] = 100;
    $raw['profile']['dropped'] = 0;
    $raw['profile']['timer_overruns'] = 75;

    expect(NativeResult::fromArray($raw)?->profile?->confidence())->toBe(0.25);
});

/**
 * Deferred samples were delivered, but at a later safe point than the one
 * they were taken at — real observations, booked next door. A profile that
 * is nine-tenths deferred is not a 100% confident profile.
 */
it('counts a deferred sample as observed somewhere else', function () {
    $raw = FakeNativeRuntime::withProfile()->result;
    $raw['profile']['sample_count'] = 100;
    $raw['profile']['dropped'] = 0;
    $raw['profile']['timer_overruns'] = 0;
    $raw['profile']['deferred_samples'] = 90;

    expect(NativeResult::fromArray($raw)?->profile?->confidence())->toBe(0.1);
});

it('has no confidence in a profile with no samples at all', function () {
    $raw = FakeNativeRuntime::withProfile()->result;
    $raw['profile']['sample_count'] = 0;
    $raw['profile']['dropped'] = 0;
    $raw['profile']['timer_overruns'] = 0;

    expect(NativeResult::fromArray($raw)?->profile?->confidence())->toBe(0.0);
});

/**
 * The frame table is sized by `profiler.max_frames` — 4,096 by default —
 * so shipping it whole left the event hundreds of kilobytes wide however
 * hard max_stack_nodes truncated the tree it was there to explain.
 */
it('keeps only the frames the retained call tree refers to', function () {
    $raw = FakeNativeRuntime::withProfile()->result;
    $raw['profile']['frames'][] = ['function' => 'Unreferenced::method', 'file' => null, 'line' => 0];
    $raw['profile']['stacks'] = [[0, 1, 40]];

    $profile = NativeResult::fromArray($raw)?->profile;

    expect($profile?->frames)->toHaveCount(1)
        ->and($profile?->frames[1]['function'])->toBe('PDO::query');
});

/**
 * A node is created after its parent, so any PREFIX of the emission order is
 * parent-closed. Truncating by sample count instead would orphan survivors.
 */
it('truncates a call tree in emission order so every parent survives', function () {
    $raw = FakeNativeRuntime::withProfile()->result;
    $raw['profile']['stacks'] = [[0, 0, 100], [1, 1, 60], [2, 0, 20], [3, 1, 5]];

    $profile = NativeResult::fromArray($raw, maxStackNodes: 2)?->profile;

    expect($profile?->stacks)->toBe([[0, 0, 100], [1, 1, 60]])
        // The table those ids resolve against, or the tree is a list of ints.
        ->and($profile?->frames)->toHaveCount(2);
});

it('keeps no frame table when no call tree was asked for', function () {
    $profile = NativeResult::fromArray(FakeNativeRuntime::withProfile()->result)?->profile;

    expect($profile?->stacks)->toBeNull()
        ->and($profile?->frames)->toBe([]);
});
