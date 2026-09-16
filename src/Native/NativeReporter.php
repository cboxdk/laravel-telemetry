<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Native;

use Cbox\Telemetry\Support\Cast;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\Span;

/**
 * Turns one unit's native measurements into this package's vocabulary:
 * span attributes for the unit in hand, metrics for the ones nobody will
 * ever look at a trace for, and the `profile.captured` event.
 *
 * Metrics deliberately carry only `operation` and `unit` as labels, never
 * the route or job name the span already has. Traces are sampled; a
 * connection-establishment regression has to be visible without one, and
 * it does not have to be visible per route to be visible.
 */
final class NativeReporter
{
    /** Per-unit time spent inside one native operation, in milliseconds. */
    private const OPERATION_BUCKETS = [1, 5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000];

    /**
     * Call this while the span is still open — the aggregates belong to it.
     *
     * @param  array<string, scalar|null>  $eventAttributes  what the call site
     *                                                       knows and this class
     *                                                       does not: route, job
     *                                                       name, command
     */
    public static function report(
        TelemetryManager $telemetry,
        NativeResult $result,
        ?Span $span = null,
        array $eventAttributes = [],
    ): void {
        FailSafe::guard(static function () use ($telemetry, $result, $span, $eventAttributes): void {
            if (Cast::bool(config('telemetry.native.operations'), true)) {
                self::reportOperations($telemetry, $result, $span);
            }

            if (Cast::bool(config('telemetry.native.counters'), true)) {
                self::reportCounters($telemetry, $result, $span);
            }

            self::reportProfile($telemetry, $result, $eventAttributes);
        });
    }

    private static function reportOperations(TelemetryManager $telemetry, NativeResult $result, ?Span $span): void
    {
        foreach ($result->operations as $operation => $aggregate) {
            $span?->setAttributes([
                "{$operation}.count" => $aggregate['count'],
                "{$operation}.duration_ms" => $aggregate['total_ms'],
                "{$operation}.max_ms" => $aggregate['max_ms'],
            ]);

            $labels = ['operation' => $operation, 'unit' => $result->unit];

            $telemetry
                ->counter('runtime.operations', 'Native operation calls by type', '{call}')
                ->inc($aggregate['count'], $labels);

            $telemetry
                ->histogram('runtime.operation.duration', buckets: self::OPERATION_BUCKETS, description: 'Time one unit of work spent inside a native operation', unit: 'ms')
                ->record($aggregate['total_ms'], $labels);
        }

        // Nesting deeper than the extension's fixed stack. The aggregates
        // are still correct for everything that fit; saying so beats a
        // silently short number.
        if (($overflow = $result->counter('ops.overflow')) > 0) {
            $span?->setAttribute('php.native.operations_dropped', $overflow);
        }
    }

    private static function reportCounters(TelemetryManager $telemetry, NativeResult $result, ?Span $span): void
    {
        $runs = $result->counter('gc.runs');
        $collected = $result->counter('gc.collected');

        $span?->setAttributes([
            'php.gc.runs' => $runs,
            'php.gc.collected' => $collected,
        ]);

        if ($runs > 0) {
            $labels = ['unit' => $result->unit];

            $telemetry->counter('runtime.gc.runs', 'PHP cycle collector runs', '{run}')->inc($runs, $labels);
            $telemetry->counter('runtime.gc.collected', 'Objects freed by the cycle collector', '{object}')->inc($collected, $labels);
        }
    }

    /**
     * @param  array<string, scalar|null>  $eventAttributes
     */
    private static function reportProfile(TelemetryManager $telemetry, NativeResult $result, array $eventAttributes): void
    {
        $profile = $result->profile;

        if ($profile === null || $profile->topFunctions === []) {
            return;
        }

        $attributes = [
            ...$eventAttributes,
            'duration_ms' => round($result->durationMs, 2),
            'profile.source' => 'native',
            'profile.sample_count' => $profile->sampleCount,
            'profile.period_ns' => $profile->periodNs,
            'profile.clock' => $profile->clock,
            // What the profile is worth: samples that could not be taken at a
            // safe point, and ticks the kernel never delivered, are the
            // difference between "this is where the time went" and "this is
            // arithmetic".
            'profile.confidence' => $profile->confidence(),
            'profile.dropped' => $profile->dropped,
            'profile.timer_overruns' => $profile->timerOverruns,
            'profile.top_functions' => json_encode($profile->topFunctions, JSON_UNESCAPED_SLASHES) ?: '[]',
        ];

        if ($profile->capped) {
            $attributes['profile.capped'] = true;
        }

        if ($profile->stacks !== null) {
            $attributes['profile.stacks'] = json_encode($profile->stacks) ?: '[]';
            $attributes['profile.frames'] = json_encode($profile->frames, JSON_UNESCAPED_SLASHES) ?: '[]';
        }

        $telemetry->event('profile.captured', $attributes);
    }
}
