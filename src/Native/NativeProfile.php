<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Native;

use Cbox\Telemetry\Support\Cast;

/**
 * An aggregated CPU profile for one unit of work.
 *
 * The extension emits frames once and refers to them by id afterwards, so
 * nothing repeats a string it has already sent. That is the right shape on
 * the wire and the wrong shape for an event attribute, so ids are resolved
 * here — once, at the boundary.
 *
 * `dropped` and `timerOverruns` travel with the profile deliberately: a
 * profile that could not be sampled at a safe point, or whose ticks the
 * kernel never delivered, is still a profile — it just deserves less trust,
 * and saying so is cheaper than having someone act on arithmetic.
 */
final readonly class NativeProfile
{
    /**
     * @param  list<array{function: string, samples: int, file?: string, line?: int}>  $topFunctions
     * @param  list<array{int, int, int}>|null  $stacks  flat (parent, frame, samples) triples
     * @param  array<int, array{function: string, file?: string, line?: int}>  $frames  the
     *                                                                                  table `$stacks` refers to, kept
     *                                                                                  only when stacks were asked for
     */
    private function __construct(
        public int $sampleCount,
        public int $periodNs,
        public string $clock,
        public int $dropped,
        public int $timerOverruns,
        public int $deferredSamples,
        public bool $capped,
        public array $topFunctions,
        public ?array $stacks,
        public array $frames = [],
    ) {}

    /**
     * @param  array<string, int|bool>  $counters  the unit's counters, which
     *                                             carry the profiler health
     *                                             figures the profile itself
     *                                             does not repeat
     */
    public static function fromArray(mixed $raw, array $counters, int $topFunctions, int $maxStackNodes = 2048): ?self
    {
        if (! is_array($raw)) {
            return null;
        }

        $profile = Cast::stringKeyedArray($raw);
        $frames = self::frames($profile['frames'] ?? null);

        $top = [];

        foreach (Cast::array($profile['top_functions'] ?? null) as $entry) {
            if (count($top) >= max(1, $topFunctions)) {
                break;
            }

            $entry = Cast::stringKeyedArray($entry);
            $frame = $frames[Cast::int($entry['frame_id'] ?? null, -1)] ?? null;

            if ($frame === null) {
                continue;
            }

            $top[] = [...$frame, 'samples' => Cast::int($entry['samples'] ?? null)];
        }

        $capped = $counters['profiler.capped'] ?? false;
        $stacks = self::stacks($profile['stacks'] ?? null, $maxStackNodes);

        return new self(
            sampleCount: Cast::int($profile['sample_count'] ?? null),
            periodNs: Cast::int($profile['period_ns'] ?? null),
            clock: Cast::string($profile['clock'] ?? null, 'unknown'),
            dropped: Cast::int($profile['dropped'] ?? null),
            timerOverruns: Cast::int($profile['timer_overruns'] ?? null),
            deferredSamples: Cast::int($profile['deferred_samples'] ?? null),
            capped: is_bool($capped) ? $capped : false,
            topFunctions: $top,
            stacks: $stacks,
            // The triples are frame ids; without the table they resolve
            // against, a call tree is a list of integers. Only the frames
            // the RETAINED triples name are kept: the table is sized by
            // `profiler.max_frames` (4,096 by default), so shipping it
            // whole would leave the event hundreds of kilobytes wide no
            // matter how hard max_stack_nodes truncated the tree.
            frames: $stacks === null ? [] : self::referencedFrames($frames, $stacks),
        );
    }

    /**
     * How much of this profile was observed where it says it was observed.
     *
     * The arithmetic follows the extension's accounting, which is easy to
     * get wrong in the flattering direction. A sample carries the WEIGHT of
     * every tick it accounts for, overruns included — so `sample_count` is
     * ticks, not stack walks, and reading it as "samples we got" while
     * adding the overruns as "samples we missed" both inflates the
     * numerator and double-counts the denominator.
     *
     * Accounted ticks are `sample_count + dropped`. Of those:
     *
     * - `timer_overruns` were never delivered — nothing was observed, the
     *   weight was folded into whichever stack was walked next;
     * - `deferred_samples` were delivered late, so they are real
     *   observations booked somewhere other than where they were taken;
     * - `dropped` were observed but had nowhere to go (frame table, trie
     *   or arena full).
     *
     * What is left is the share of this profile that means what it appears
     * to mean.
     */
    public function confidence(): float
    {
        $accounted = $this->sampleCount + $this->dropped;

        if ($accounted <= 0) {
            return 0.0;
        }

        $trustworthy = $accounted - $this->dropped - $this->timerOverruns - $this->deferredSamples;

        return round(max(0.0, min(1.0, $trustworthy / $accounted)), 4);
    }

    /**
     * @param  array<int, array{function: string, file?: string, line?: int}>  $frames
     * @param  list<array{int, int, int}>  $stacks
     * @return array<int, array{function: string, file?: string, line?: int}>
     */
    private static function referencedFrames(array $frames, array $stacks): array
    {
        $kept = [];

        foreach ($stacks as [$parent, $frameId, $samples]) {
            if (isset($frames[$frameId])) {
                $kept[$frameId] = $frames[$frameId];
            }
        }

        return $kept;
    }

    /**
     * Frames arrive as a list whose position IS the frame id.
     *
     * @return array<int, array{function: string, file?: string, line?: int}>
     */
    private static function frames(mixed $raw): array
    {
        $frames = [];

        foreach (Cast::array($raw) as $id => $frame) {
            if (! is_int($id)) {
                continue;
            }

            $frame = Cast::stringKeyedArray($frame);
            $entry = ['function' => Cast::string($frame['function'] ?? null, '<unknown>')];

            if (is_string($frame['file'] ?? null)) {
                $entry['file'] = Cast::string($frame['file']);
                $entry['line'] = Cast::int($frame['line'] ?? null);
            }

            $frames[$id] = $entry;
        }

        return $frames;
    }

    /**
     * Truncation is safe in emission order and only in emission order: a
     * node is created after its parent, so any prefix of the list is
     * parent-closed. Sorting by samples first would orphan the survivors.
     *
     * @return list<array{int, int, int}>|null
     */
    private static function stacks(mixed $raw, int $max): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $stacks = [];

        foreach ($raw as $node) {
            if (count($stacks) >= max(1, $max)) {
                break;
            }

            $node = Cast::array($node);

            if (count($node) !== 3) {
                continue;
            }

            $stacks[] = [
                Cast::int($node[0] ?? null),
                Cast::int($node[1] ?? null),
                Cast::int($node[2] ?? null),
            ];
        }

        return $stacks;
    }
}
