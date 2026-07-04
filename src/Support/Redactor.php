<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

use Cbox\Telemetry\Events\TelemetryEvent;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanEvent;
use Closure;

/**
 * The redaction engine — one choke point through which every span
 * attribute, span event (exception messages!) and telemetry event passes
 * at flush time, before any exporter sees it.
 *
 * Two built-in strategies plus an app hook:
 *
 * - Key-based: attribute keys whose dot/underscore segments match a
 *   configured word ("password", "api_key", …) have their whole value
 *   replaced. Segment matching, not substring — `cache.key` is safe
 *   while `stripe.api_key` is caught.
 * - Pattern-based: regexes scrub secrets EMBEDDED in any string value —
 *   JWTs, Bearer/Basic credentials, url userinfo — wherever they appear
 *   (exception messages, SQL comments, event payloads).
 * - Custom: Telemetry::redactUsing(fn ($key, $value) => ...) runs last.
 *
 * A broken custom pattern never breaks telemetry: patterns that fail to
 * compile are skipped, and the hook is guarded.
 */
final class Redactor
{
    private ?Closure $custom = null;

    /**
     * @param  list<string>  $keys
     * @param  array<string, string>  $patterns  regex => replacement
     * @param  list<string>  $safeKeys
     */
    public function __construct(
        private readonly bool $enabled = true,
        private readonly array $keys = [],
        private readonly array $patterns = [],
        private readonly string $replacement = '[REDACTED]',
        private readonly array $safeKeys = [],
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $keys = $config['keys'] ?? null;
        $patterns = $config['patterns'] ?? null;

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            keys: is_array($keys) ? array_values(array_filter($keys, is_string(...))) : self::defaultKeys(),
            patterns: is_array($patterns) ? array_filter($patterns, is_string(...)) : self::defaultPatterns(),
            replacement: is_string($config['replacement'] ?? null) ? $config['replacement'] : '[REDACTED]',
            safeKeys: is_array($config['safe_keys'] ?? null)
                ? array_values(array_filter($config['safe_keys'], is_string(...)))
                : self::defaultSafeKeys(),
        );
    }

    /**
     * @return list<string>
     */
    public static function defaultKeys(): array
    {
        return [
            'password', 'passwd', 'secret', 'token', 'api_key', 'apikey',
            'auth', 'authorization', 'signature', 'credential', 'credentials',
            'private_key', 'credit_card', 'card_number', 'cvv', 'ssn', 'session',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function defaultPatterns(): array
    {
        return [
            // JWTs — three base64url segments.
            '/\beyJ[\w-]{10,}\.[\w-]{6,}\.[\w-]{6,}/' => '[REDACTED:jwt]',
            // HTTP credential schemes embedded in messages.
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{16,}/i' => '$1 [REDACTED]',
            // Userinfo in URLs: scheme://user:pass@host.
            '#\b([a-z][a-z0-9+.-]*://)[^/@\s:]+:[^/@\s]+@#i' => '$1[REDACTED]@',
        ];
    }

    /**
     * Exact attribute keys exempt from KEY-based redaction — the
     * package's own attributes whose keys contain sensitive-looking
     * segments but whose values are known-safe by construction.
     * Pattern scrubbing and the custom hook still apply to them.
     *
     * @return list<string>
     */
    public static function defaultSafeKeys(): array
    {
        // session.id is the OTel semantic-convention session identifier (the
        // analytics keystone) — a salted hash by construction, never the raw
        // Laravel session id (that is only ever stamped, hashed, as
        // session.hash). Safe to record despite the "session" segment.
        return ['session.driver', 'session.hash', 'session.id'];
    }

    /**
     * Runs after the built-ins. Return a replacement string, or null to
     * keep the value untouched.
     *
     * @param  (Closure(string, string): ?string)|null  $custom
     */
    public function redactUsing(?Closure $custom): void
    {
        $this->custom = $custom;
    }

    /**
     * @param  list<Span>  $spans
     * @return list<Span>
     */
    public function spans(array $spans): array
    {
        if (! $this->enabled) {
            return $spans;
        }

        foreach ($spans as $span) {
            $span->setAttributes($this->attributes($span->attributes()));

            $span->replaceEvents(array_map(
                fn (SpanEvent $event): SpanEvent => new SpanEvent($event->name, $event->timeUnixNano, $this->attributes($event->attributes)),
                $span->events(),
            ));
        }

        return $spans;
    }

    /**
     * @param  list<TelemetryEvent>  $events
     * @return list<TelemetryEvent>
     */
    public function events(array $events): array
    {
        if (! $this->enabled) {
            return $events;
        }

        return array_map(fn (TelemetryEvent $event): TelemetryEvent => new TelemetryEvent(
            // Log records carry the log MESSAGE as their name — free-form
            // text that needs the same scrubbing as any attribute value.
            name: $this->value($event->severityText !== null ? 'log.message' : 'event.name', $event->name),
            timeUnixNano: $event->timeUnixNano,
            attributes: $this->attributes($event->attributes),
            traceId: $event->traceId,
            spanId: $event->spanId,
            severityNumber: $event->severityNumber,
            severityText: $event->severityText,
        ), $events);
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     * @return array<string, scalar|null>
     */
    public function attributes(array $attributes): array
    {
        if (! $this->enabled) {
            return $attributes;
        }

        foreach ($attributes as $key => $value) {
            if ($value === null) {
                continue;
            }

            // Key-based redaction applies regardless of value TYPE — a
            // sensitive key holding an int PIN, an OTP token or a bool
            // must not slip past the string-only pattern scrubbing.
            if (is_string($value)) {
                if ($value !== '') {
                    $attributes[$key] = $this->value($key, $value);
                }
            } elseif ($this->keyIsSensitive($key)) {
                $attributes[$key] = $this->replacement;
            }
        }

        return $attributes;
    }

    public function value(string $key, string $value): string
    {
        if (! $this->enabled) {
            return $value;
        }

        if ($this->keyIsSensitive($key)) {
            return $this->replacement;
        }

        foreach ($this->patterns as $pattern => $replacement) {
            if (! $this->patternCompiles($pattern)) {
                continue;
            }

            $scrubbed = preg_replace($pattern, $replacement, $value);

            if (is_string($scrubbed)) {
                $value = $scrubbed;
            }
        }

        if ($this->custom !== null) {
            $value = FailSafe::guard(fn (): string => ($this->custom)($key, $value) ?? $value) ?? $value;
        }

        return $value;
    }

    /** @var array<string, bool> */
    private array $compiles = [];

    /**
     * A pattern that fails to compile is skipped (checked once, silently
     * — a broken config entry must never break telemetry).
     */
    private function patternCompiles(string $pattern): bool
    {
        return $this->compiles[$pattern] ??= (function () use ($pattern): bool {
            set_error_handler(static fn (): bool => true);

            try {
                return preg_match($pattern, '') !== false;
            } finally {
                restore_error_handler();
            }
        })();
    }

    /**
     * Matches whole dot/dash/underscore-separated segments of the key,
     * so `cache.key` never trips on "key" while `stripe.api_key` and
     * `http.request.header.authorization` are caught.
     */
    public function keyIsSensitive(string $key): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $normalized = strtolower($key);

        if (in_array($normalized, array_map(strtolower(...), $this->safeKeys), true)) {
            return false;
        }

        foreach ($this->keys as $word) {
            $word = preg_quote(strtolower($word), '/');

            if (preg_match("/(^|[._-]){$word}([._-]|\$)/", $normalized) === 1) {
                return true;
            }
        }

        return false;
    }
}
