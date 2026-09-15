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
        // The package lists are UNIONED with whatever the app configured,
        // unless it explicitly asks to replace them.
        //
        // `mergeConfigFrom()` is a shallow `array_merge`, so a published
        // `config/telemetry.php` replaces this block whole and can never
        // receive an entry added later — and the entries added later are the
        // ones that catch newly-understood credential spellings. An app that
        // published two versions ago should not be quietly less protected than
        // one that did not publish at all.
        //
        // Set `redaction.replace_defaults` to true to get the old semantics
        // and control the lists outright.
        $replace = (bool) ($config['replace_defaults'] ?? false);

        $keys = self::stringList($config['keys'] ?? null);
        $patterns = is_array($config['patterns'] ?? null)
            ? array_filter($config['patterns'], is_string(...))
            : null;
        $safeKeys = self::stringList($config['safe_keys'] ?? null);

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            keys: self::union(self::defaultKeys(), $keys, $replace),
            patterns: $patterns === null
                ? self::defaultPatterns()
                : ($replace ? $patterns : [...self::defaultPatterns(), ...$patterns]),
            replacement: is_string($config['replacement'] ?? null) ? $config['replacement'] : '[REDACTED]',
            safeKeys: self::union(self::defaultSafeKeys(), $safeKeys, $replace),
        );
    }

    /**
     * The pass the patterns cannot do: match a parameter by its DECODED name.
     *
     * A regex matches literal text, so `%74oken=` and `token%5Ba%5D=` walk
     * straight past a pattern written for `token=`. Decoding the value to
     * match it would mean publishing something the caller never sent, so the
     * NAME is decoded instead and the original text is left exactly as it was
     * apart from the credential itself.
     *
     * Ambiguous names are honoured only after `?`, `&` or `;` — a real query
     * context. At the start of a value or after whitespace, `key=abc` is a
     * cache key and `code=200` is a status, and blanking those protects
     * nothing.
     *
     * Idempotent: a value already replaced re-matches and is replaced with
     * itself, which is what lets the capture pass and this one both run.
     */
    private function redactEncodedParameters(string $value): string
    {
        if (! str_contains($value, '=')) {
            return $value;
        }

        $scrubbed = preg_replace_callback(
            '/(^|[?&;\s])([^=&;?\s]{1,64})=([^&\s]+)/',
            function (array $m): string {
                $ambiguous = $m[1] === '?' || $m[1] === '&' || $m[1] === ';';

                return self::parameterIsCredential($m[2], $ambiguous)
                    ? $m[1].$m[2].'='.$this->replacement
                    : $m[0];
            },
            $value,
        );

        if (! is_string($scrubbed)) {
            // Same reasoning as the pattern loop: the one value long enough to
            // defeat the matcher must not be the one value that escapes it.
            return preg_last_error() !== PREG_NO_ERROR ? $this->replacement : $value;
        }

        return $scrubbed;
    }

    /**
     * @return list<string>|null
     */
    private static function stringList(mixed $value): ?array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : null;
    }

    /**
     * @param  list<string>  $defaults
     * @param  list<string>|null  $configured
     * @return list<string>
     */
    private static function union(array $defaults, ?array $configured, bool $replace): array
    {
        if ($configured === null) {
            return $defaults;
        }

        return $replace ? $configured : array_values(array_unique([...$defaults, ...$configured]));
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
     * Parameter names that are ALWAYS a credential, matched as a suffix after
     * a word boundary — `api_token`, `access_token`, `x-api-key`.
     *
     * @var list<string>
     */
    public const CREDENTIAL_PARAMETERS = [
        'token', 'secret', 'passwd', 'password', 'api_key', 'apikey', 'signature',
        // Never an ordinary parameter name, so they do not need a query
        // context the way `key` and `code` do.
        'pwd', 'sig', 'jwt', 'otp',
    ];

    /**
     * Names that are a credential only as a whole word, and only inside
     * something that is actually a query.
     *
     * `key` is an API key and `code` is an OAuth authorization code — but
     * `sort_key` is a sort order, `postal_code` is an address, and a
     * `cache.key` attribute whose entire value is `key=abc` is neither.
     *
     * @var list<string>
     */
    public const AMBIGUOUS_PARAMETERS = ['key', 'auth', 'code', 'state'];

    /**
     * Is this parameter NAME a credential, however it was spelled?
     *
     * Decoded first, because `%74oken` and `access_token%5B0%5D` are the same
     * parameter to every application that reads them and to no regex that does
     * not. Trailing array levels are dropped for the same reason, all of them:
     * `token[a][b]` is still `token`, while `filters[postal_code]` is the
     * parameter `filters`.
     *
     * @param  bool  $allowAmbiguous  Whether the name sits in a real query,
     *                                where `code` and `key` mean what they
     *                                say. False for loose prose.
     */
    public static function parameterIsCredential(string $name, bool $allowAmbiguous = true): bool
    {
        // Decode and strip only when there is something to decode or strip:
        // almost every name is plain, and this runs once per pair.
        if (str_contains($name, '%')) {
            $name = rawurldecode($name);
        }

        $name = strtolower($name);

        if (str_contains($name, '[')) {
            $name = preg_replace('/(?:\[[^\]]*\])+$/', '', $name) ?? $name;
        }

        // `-` and `.` separate a name the way `_` does, so a header-ish
        // spelling counts: `x-api-key` is `x_api_key`.
        $name = strtr($name, ['-' => '_', '.' => '_']);

        if ($allowAmbiguous && in_array($name, self::AMBIGUOUS_PARAMETERS, true)) {
            return true;
        }

        foreach (self::CREDENTIAL_PARAMETERS as $credential) {
            if ($name === $credential || str_ends_with($name, '_'.$credential)) {
                return true;
            }
        }

        return false;
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
            //
            // Two ways to qualify, because a flat length threshold cannot
            // tell a short credential from an ordinary word.
            //
            // Sixteen characters is enough on its own — but `=` counts only as
            // trailing base64 padding, never inside, or `Bearer
            // error=invalid_token` (a real WWW-Authenticate header) reads as
            // nineteen characters of credential.
            //
            // Otherwise, from four characters, one that is neither a lowercase
            // letter nor the FIRST character. An English word capitalises only
            // its first letter and carries no digit, so `Authentication`,
            // `auth`, `realm` and `scheme` stay; `YTpi`, `abc123` and
            // `dXNlcjpwYXNz` — base64 for user:pass, twelve characters — go.
            // `=`, `.` and `-` do not qualify a token on their own: they are
            // ordinary punctuation in `realm=api`.
            //
            // Only the scheme is case-insensitive. An `/i` over the whole
            // pattern makes `[A-Z0-9]` match lowercase too and swallows the
            // prose, which is how the first attempt at this failed.
            '/\b((?i:Bearer|Basic))\s+(?:[A-Za-z0-9._~+\/-]{16,}={0,2}|(?=[A-Za-z0-9._~+\/=-]{4,})[A-Za-z][a-z]*[A-Z0-9_~+\/][A-Za-z0-9._~+\/=-]*)/' => '$1 [REDACTED]',
            // Userinfo in URLs: scheme://user:pass@host.
            '#\b([a-z][a-z0-9+.-]*://)[^/@\s:]+:[^/@\s]+@#i' => '$1[REDACTED]@',
            // A credential carried as a query parameter, wherever the string
            // came from: url.query, a referer header, an exception message
            // that quotes a URL, a log line. One pattern here reaches all of
            // them, because every attribute value passes through this class —
            // scrubbing url.query alone left the same secret in the other
            // three.
            //
            // The name matches loosely on purpose: `api_token`, `accessToken`,
            // `_token` and `token[]` are all the same secret. A name written
            // percent-encoded (`%74oken=`, `token%5B%5D=`) is NOT caught here
            // — the pattern matches literal text, and decoding it would mean
            // rewriting the value. Query strings are decoded and matched by
            // parameter name at capture time instead, in TraceRequest. Only words that are ALWAYS credentials are matched
            // this way — `code`, `state` and `key` are ordinary parameters as
            // often as they are secrets, and redacting `postal_code` protects
            // nothing while destroying real telemetry.
            //
            // Whitespace counts as a separator alongside `?`, `&` and `;`, so
            // a credential quoted in prose — `Invalid api_token=sk_live_9` in
            // an exception message — is caught too, not only one sitting in a
            // query string. The name still has to end in a credential word
            // immediately before the `=`, which is what keeps `token_count=`,
            // `signature_required=` and `secret_count=` out of it.
            '/((?:^|[?&;\s])[^=&;\s]{0,48}(?:token|secret|passwd|password|api[_\-.]?key|apikey|signature)[\[\]0-9]{0,8}=)[\'"]*[^&\s]+/i' => '$1[REDACTED]',
            // A credential value runs to the next `&` or to whitespace —
            // nothing else ends it. It used to stop at a `;` or a quote, so
            // `access_token=abc;more` and `password=abc'SECRET` published
            // everything past that character, and `access_token=""SECRET`
            // matched nothing at all because one optional quote could not get
            // past two. `;` is not a query separator in PHP anyway.
            //
            // The ambiguous words, matched EXACTLY, never with a prefix, and
            // only inside something that is actually a query — after `?`, `&`
            // or `;`, never at the start of a value. `?code=` on an OAuth
            // callback is an authorization code and the reason this list
            // exists; `postal_code=` is an address, and a `cache.key`
            // attribute whose whole value is `key=abc` is not a credential at
            // all.
            '/([?&;](?:code|state|key|auth|pwd|sig|jwt|otp)=)[\'"]*[^&\s]+/i' => '$1[REDACTED]',
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

            // The status description is free text and is exported as
            // status.message, but it was the one field redaction never saw.
            // recordException() puts the exception message in BOTH the event
            // attribute and here, so a credential inside it went out scrubbed
            // in one place and verbatim in the other — the leak wearing the
            // mask's own clothes.
            $description = $span->statusDescription();

            if (is_string($description) && $description !== '') {
                $span->setStatus($span->status(), $this->value('exception.message', $description));
            }
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

                continue;
            }

            // preg_replace() returned null. A pattern that cannot compile was
            // already skipped above, so this is the engine giving up — a
            // backtrack or recursion limit on a pathological value. Keeping
            // the original would mean the one value long enough to defeat the
            // matcher is the one value that escapes it, which is the wrong way
            // round for a redactor.
            if (preg_last_error() !== PREG_NO_ERROR) {
                return $this->replacement;
            }
        }

        $value = $this->redactEncodedParameters($value);

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
