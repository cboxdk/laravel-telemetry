<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Directives;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\Support\OwnWork;
use Cbox\Telemetry\Support\SharedState;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use InvalidArgumentException;

/**
 * Where directives live: the application's cache, so every host and
 * worker sees the same list, and a UI or insights install elsewhere can
 * change it through the signed endpoint.
 *
 * The hot path is the empty case — almost always there are no directives
 * — and it must not cost a cache round trip per request. An empty read is
 * remembered in SharedState (APCu when available: one shared-memory read
 * per request) for `recheck_seconds`, so a directive added on another host
 * takes effect here within that window; one added on THIS host takes
 * effect at once. A cache that cannot be read counts as empty for the
 * same window: a dead cache must not cost every request a timeout.
 *
 * Read-modify-write without a lock: two directives added in the same
 * instant from two places can lose one. It is a control plane for
 * humans, not a queue.
 */
final class DirectiveStore
{
    private const KEY = 'telemetry:directives';

    private const EMPTY_KEY = 'directives.empty';

    /** @var list<Directive>|null loaded once per unit of work */
    private ?array $loaded = null;

    /**
     * @param  Closure(): Repository  $cache
     */
    public function __construct(
        private readonly Closure $cache,
        private readonly int $recheckSeconds = 15,
        private readonly int $max = 50,
        private readonly int $maxMinutes = 1440,
    ) {}

    /**
     * The directives in force — the hot path.
     *
     * @return list<Directive>
     */
    public function active(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        if (SharedState::deadline(self::EMPTY_KEY) > time()) {
            return $this->loaded = [];
        }

        $directives = FailSafe::guard(fn (): array => $this->read()) ?? [];

        if ($directives === []) {
            SharedState::remember(self::EMPTY_KEY, time() + $this->recheckSeconds);
        }

        return $this->loaded = $directives;
    }

    /**
     * Forget what this unit of work loaded, so the next one reads again.
     */
    public function forgetLoaded(): void
    {
        $this->loaded = null;
    }

    /**
     * @return list<Directive>
     */
    public function all(): array
    {
        return $this->read();
    }

    public function add(Directive $directive): Directive
    {
        $ceiling = time() + $this->maxMinutes * 60;

        if ($directive->expired()) {
            throw new InvalidArgumentException('A directive must expire in the future.');
        }

        if ($directive->expiresAt > $ceiling) {
            $directive = $directive->until($ceiling);
        }

        $directives = array_values(array_filter(
            $this->read(),
            static fn (Directive $existing): bool => $existing->id !== $directive->id,
        ));

        if (count($directives) >= $this->max) {
            throw new InvalidArgumentException("At most {$this->max} directives can be active at once.");
        }

        $directives[] = $directive;

        $this->write($directives);

        return $directive;
    }

    public function remove(string $id): bool
    {
        $directives = $this->read();
        $kept = array_values(array_filter($directives, static fn (Directive $d): bool => $d->id !== $id));

        if (count($kept) === count($directives)) {
            return false;
        }

        $this->write($kept);

        return true;
    }

    /**
     * True the first time a key is claimed within the TTL, false after —
     * the replay guard of the signed endpoint.
     */
    public function claimOnce(string $key, int $ttlSeconds): bool
    {
        return OwnWork::run(fn (): bool => ($this->cache)()->add(self::KEY.':'.$key, true, $ttlSeconds));
    }

    public function clear(): void
    {
        OwnWork::run(fn () => ($this->cache)()->forget(self::KEY));
        $this->loaded = null;
    }

    /**
     * @return list<Directive>
     */
    private function read(): array
    {
        $raw = OwnWork::run(fn (): mixed => ($this->cache)()->get(self::KEY));

        if (! is_array($raw)) {
            return [];
        }

        $now = time();
        $directives = [];

        foreach ($raw as $entry) {
            // One malformed entry costs itself, not the list.
            $directive = is_array($entry) ? FailSafe::guard(static fn (): Directive => Directive::fromArray($entry)) : null;

            if ($directive !== null && ! $directive->expired($now)) {
                $directives[] = $directive;
            }
        }

        return $directives;
    }

    /**
     * @param  list<Directive>  $directives
     */
    private function write(array $directives): void
    {
        OwnWork::run(function () use ($directives): void {
            $cache = ($this->cache)();

            if ($directives === []) {
                $cache->forget(self::KEY);

                return;
            }

            $until = max(array_map(static fn (Directive $d): int => $d->expiresAt, $directives));

            $cache->put(self::KEY, array_map(static fn (Directive $d): array => $d->toArray(), $directives), max(1, $until - time()));
        });

        // This host sees the change now; others within recheck_seconds.
        SharedState::forget(self::EMPTY_KEY);
        $this->loaded = null;
    }
}
