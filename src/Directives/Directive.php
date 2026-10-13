<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Directives;

use Cbox\Telemetry\Support\Cast;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A time-limited instruction to record more than usual for some slice of
 * the traffic: "keep every trace of user 42 for the next two hours",
 * "profile checkout.* for 15 minutes", "full detail for tenant.id=7".
 *
 * The thing a sample rate cannot do. A 1% rate is right for the fleet and
 * useless for the one customer on the phone; a directive turns the
 * recording up for exactly that slice, and turns itself off again.
 *
 *     Telemetry::directives()->add(
 *         Directive::user('42')->profile()->for(minutes: 120)->because('ticket #881'),
 *     );
 *
 * Every directive expires — there is no "forever", by design: a forgotten
 * directive is a sample rate of 100% that nobody remembers setting.
 */
final readonly class Directive
{
    public const MATCH_ROUTE = 'route';

    public const MATCH_PATH = 'path';

    public const MATCH_USER = 'user';

    public const MATCH_CONTEXT = 'context';

    public const MATCH_JOB = 'job';

    private const MATCHES = [self::MATCH_ROUTE, self::MATCH_PATH, self::MATCH_USER, self::MATCH_CONTEXT, self::MATCH_JOB];

    /**
     * @param  string  $match  one of the MATCH_* kinds
     * @param  string|null  $key  the context dimension, for MATCH_CONTEXT
     * @param  string  $value  a Str::is() pattern for route/path/job, an exact value otherwise
     */
    public function __construct(
        public string $id,
        public string $match,
        public ?string $key,
        public string $value,
        public bool $profile = false,
        public int $expiresAt = 0,
        public ?string $reason = null,
        public ?string $createdBy = null,
    ) {
        if (! in_array($match, self::MATCHES, true)) {
            throw new InvalidArgumentException("Unknown directive match [{$match}].");
        }

        if ($match === self::MATCH_CONTEXT && ($key === null || $key === '')) {
            throw new InvalidArgumentException('A context directive needs the dimension it matches on.');
        }

        if ($value === '') {
            throw new InvalidArgumentException('A directive needs a value to match.');
        }
    }

    /** A route name or URI template, e.g. `checkout.*` or `api/orders/{order}`. */
    public static function route(string $pattern): self
    {
        return self::make(self::MATCH_ROUTE, null, $pattern);
    }

    /** A request path, no leading slash, e.g. `admin/*`. */
    public static function path(string $pattern): self
    {
        return self::make(self::MATCH_PATH, null, ltrim($pattern, '/'));
    }

    /** The authenticated user's id (`user.id`). */
    public static function user(string|int $id): self
    {
        return self::make(self::MATCH_USER, null, (string) $id);
    }

    /** A Telemetry::context() dimension, e.g. `tenant.id` = `7`. */
    public static function context(string $key, string|int $value): self
    {
        return self::make(self::MATCH_CONTEXT, $key, (string) $value);
    }

    /** A queued job class, e.g. `App\Jobs\SyncInvoices` or `App\Jobs\Billing\*`. */
    public static function job(string $pattern): self
    {
        return self::make(self::MATCH_JOB, null, $pattern);
    }

    /** Also capture a CPU profile (native extension, or excimer for requests). */
    public function profile(bool $profile = true): self
    {
        return $this->copy(profile: $profile);
    }

    public function for(int $minutes): self
    {
        return $this->copy(expiresAt: time() + max(1, $minutes) * 60);
    }

    public function until(int $unixTime): self
    {
        return $this->copy(expiresAt: $unixTime);
    }

    public function because(?string $reason): self
    {
        return $this->copy(reason: [$reason === null ? null : Str::limit($reason, 200, '')]);
    }

    public function by(?string $actor): self
    {
        return $this->copy(createdBy: [$actor === null ? null : Str::limit($actor, 100, '')]);
    }

    public function expired(?int $now = null): bool
    {
        return $this->expiresAt <= ($now ?? time());
    }

    /**
     * @param  array<string, scalar|null>  $facts  route, path, user, job and context dimensions known so far
     */
    public function matches(array $facts): bool
    {
        if ($this->match === self::MATCH_CONTEXT) {
            $actual = $facts['context:'.$this->key] ?? null;

            return $actual !== null && (string) $actual === $this->value;
        }

        if ($this->match === self::MATCH_USER) {
            return isset($facts['user']) && (string) $facts['user'] === $this->value;
        }

        // A route matches by name OR by its URI template, so an unnamed
        // route can still be targeted.
        $candidates = $this->match === self::MATCH_ROUTE
            ? [$facts['route'] ?? null, $facts['route.uri'] ?? null]
            : [$facts[$this->match] ?? null];

        foreach ($candidates as $actual) {
            if ($actual !== null && $actual !== '' && Str::is($this->value, (string) $actual)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{id: string, match: string, key: string|null, value: string, profile: bool, expires_at: int, reason: string|null, created_by: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'match' => $this->match,
            'key' => $this->key,
            'value' => $this->value,
            'profile' => $this->profile,
            'expires_at' => $this->expiresAt,
            'reason' => $this->reason,
            'created_by' => $this->createdBy,
        ];
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Cast::string($data['id'] ?? null) ?: (string) Str::ulid(),
            match: Cast::string($data['match'] ?? null),
            key: isset($data['key']) ? Cast::string($data['key']) : null,
            value: Cast::string($data['value'] ?? null),
            profile: (bool) ($data['profile'] ?? false),
            expiresAt: Cast::int($data['expires_at'] ?? null, 0),
            reason: isset($data['reason']) ? Str::limit(Cast::string($data['reason']), 200, '') : null,
            createdBy: isset($data['created_by']) ? Str::limit(Cast::string($data['created_by']), 100, '') : null,
        );
    }

    private static function make(string $match, ?string $key, string $value): self
    {
        // An hour unless told otherwise — long enough to reproduce
        // something, short enough to be forgotten safely.
        return new self((string) Str::ulid(), $match, $key, $value, expiresAt: time() + 3600);
    }

    /**
     * Nullable fields are passed wrapped in a one-element array, so "set
     * to null" and "leave alone" stay distinguishable.
     *
     * @param  array{0: string|null}|null  $reason
     * @param  array{0: string|null}|null  $createdBy
     */
    private function copy(?bool $profile = null, ?int $expiresAt = null, ?array $reason = null, ?array $createdBy = null): self
    {
        return new self(
            $this->id,
            $this->match,
            $this->key,
            $this->value,
            $profile ?? $this->profile,
            $expiresAt ?? $this->expiresAt,
            $reason === null ? $this->reason : $reason[0],
            $createdBy === null ? $this->createdBy : $createdBy[0],
        );
    }
}
