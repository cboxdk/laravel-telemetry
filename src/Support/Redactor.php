<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

use Cbox\Telemetry\Contracts\RedactsTelemetry;
use Cbox\Telemetry\Events\TelemetryEvent;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanEvent;
use Cbox\Telemetry\Tracing\SpanLink;
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
final class Redactor implements RedactsTelemetry
{
    private ?Closure $custom = null;

    private ?string $prefixPattern = null;

    /**
     * @param  list<string>  $keys
     * @param  array<string, string>  $patterns  regex => replacement
     * @param  list<string>  $safeKeys
     * @param  list<string>  $credentialPrefixes
     */
    public function __construct(
        private readonly bool $enabled = true,
        private readonly array $keys = [],
        private readonly array $patterns = [],
        private readonly string $replacement = '[REDACTED]',
        private readonly array $safeKeys = [],
        /** Whether the app took the lists over, which also turns off the built-in name heuristics. */
        private readonly bool $replaceDefaults = false,
        /** Issuer prefixes that mark a value as a credential whatever it was called. */
        private readonly array $credentialPrefixes = [],
        /** Whether to judge a value by its shape when its name says nothing. */
        private readonly bool $valueShape = true,
        /** How long an unrecognised value must be before its shape is evidence. */
        private readonly int $valueMinLength = 24,
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
            // NOT unioned. `keys` and `patterns` are rules, so adding the
            // package's can only redact more; `safe_keys` are EXEMPTIONS, and
            // adding the package's back would re-expose something an app
            // deliberately stopped exempting. Unioning a rule is safe in a way
            // unioning an exemption is not.
            safeKeys: $safeKeys ?? self::defaultSafeKeys(),
            replaceDefaults: $replace,
            // Unioned like the other RULES, for the same reason: a new
            // issuer prefix added in a later version must reach an app
            // that published its config two versions ago.
            credentialPrefixes: self::union(
                self::defaultCredentialPrefixes(),
                self::stringList($config['credential_prefixes'] ?? null),
                $replace,
            ),
            valueShape: (bool) ($config['value_shape'] ?? true),
            valueMinLength: is_numeric($config['value_min_length'] ?? null)
                ? max(8, (int) $config['value_min_length'])
                : 24,
        );
    }

    /**
     * Redact the values of sensitive KEYS in a structure, in place.
     *
     * For a caller holding the array itself — the log channel, which
     * `json_encode`s any non-scalar context value and would otherwise ship
     * `['password' => 'hunter2']` as a body no pattern can match. Done here,
     * before encoding, rather than by decoding and re-encoding the JSON at
     * export: a round trip through `json_decode(..., true)` cannot tell an
     * empty object from an empty array, turns `{"0":"a","1":"b"}` into a list,
     * and rewrites big integers and float literals — corrupting data that had
     * nothing to redact in it.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public function redactStructure(array $values, int $depth = 0): array
    {
        if (! $this->enabled || $depth > 16) {
            return $values;
        }

        $redacted = [];

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $redacted[$key] = $this->redactStructure($value, $depth + 1);

                continue;
            }

            $redacted[$key] = is_string($key) && $this->keyIsSensitive($key)
                ? $this->replacement
                : $value;
        }

        return $redacted;
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
     * A quoted value is taken whole, so a password containing a space does
     * not publish its tail — but only when the closing quote actually ends the
     * value. `token=""SECRET` is not an empty quoted string followed by a
     * word; it is a value that happens to start with two quotes, and reading
     * it the other way published `SECRET`.
     *
     * Idempotent: a value already carrying the replacement is left alone,
     * checked at the value's offset in the original string so a replacement
     * containing a space is recognised whole.
     */
    private function redactEncodedParameters(string $value, int $depth = 0): string
    {
        // Turned off with the lists. An app that sets replace_defaults has
        // said the rules are its own, and this pass is a built-in rule.
        if ($this->replaceDefaults || $depth > 4 || ! str_contains($value, '=')) {
            return $value;
        }

        $scrubbed = preg_replace_callback(
            '/(^|[?&;\s])([^=&;?\s]+)=("[^"\n]*"(?=[&\s]|$)|\'[^\'\n]*\'(?=[&\s]|$)|[^&\s]+)/',
            /** @param array<int, array{0: string, 1: int}> $m */
            function (array $m) use ($value, $depth): string {
                $separator = $m[1][0];
                $ambiguous = $separator === '?' || $separator === '&' || $separator === ';';

                // The name first, because it is cheap and certain. Then
                // the VALUE, which is the only thing that can catch a
                // parameter nobody enumerated — `?t=`, `?sas=`, and
                // every name a third-party API invented. Auto-instrumented
                // URLs are full of those.
                if (! self::parameterIsCredential($m[2][0], $ambiguous)
                    && ! $this->valueLooksLikeCredential(self::decodedValue($m[3][0]))) {
                    // `url=https://x/?token=SECRET` — the outer assignment is
                    // not a credential, but the match consumed its value and
                    // with it the query inside. Look again in there, once.
                    return str_contains($m[3][0], '?')
                        ? $m[1][0].$m[2][0].'='.$this->redactEncodedParameters($m[3][0], $depth + 1)
                        : $m[0][0];
                }

                // Already replaced? Compared at the value's offset in the
                // ORIGINAL string, because a replacement containing a space —
                // `[HIDDEN VALUE]` — is captured only as far as the space and
                // would otherwise be replaced again, leaving its own tail
                // behind each time.
                //
                // substr_compare, not substr()+str_starts_with: copying the
                // rest of the input to compare a short prefix made this
                // quadratic, 640KB of `token=x&` taking 349ms.
                //
                // A prefix is not enough either. `token=[REDACTED]SECRET`
                // starts with the replacement and is emphatically not redacted,
                // so what follows must actually end the value. An empty
                // replacement matches everywhere and is no evidence at all.
                $at = $m[3][1];
                $length = strlen($this->replacement);

                if ($length > 0 && substr_compare($value, $this->replacement, $at, $length) === 0) {
                    $after = $value[$at + $length] ?? '';

                    if ($after === '' || $after === '&' || $after === ' ' || $after === "\t" || $after === "\n" || $after === "\r") {
                        return $m[0][0];
                    }
                }

                return $separator.$m[2][0].'='.$this->replacement;
            },
            $value,
            flags: PREG_OFFSET_CAPTURE,
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
            // The short spellings a structured log context uses as its own
            // key: `log.context.otp`, `log.context.sig`.
            'otp', 'sig', 'jwt',
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
        // context the way `key` and `code` do. `pwd` is NOT among them —
        // `pwd=/srv/app` is a working directory in any shell-flavoured log.
        'sig', 'jwt', 'otp',
        // A session id in a URL is a credential — that is what session
        // fixation is — and it arrives spelled both ways.
        'session_id', 'sessionid',
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
    public const AMBIGUOUS_PARAMETERS = ['key', 'auth', 'code', 'state', 'pwd'];

    /**
     * Words that turn an ambiguous parameter into an unambiguous one.
     *
     * `key` on its own could be a sort key. Behind one of these it is a
     * credential and nothing else — and `postal`, `sort`, `status` and
     * `zip` are conspicuously not here, which is the point.
     *
     * @var list<string>
     */
    public const CREDENTIAL_QUALIFIERS = [
        'private', 'secret', 'api', 'access', 'auth', 'client', 'app',
        'encryption', 'signing', 'consumer', 'shared', 'master',
    ];

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

        $original = $name;
        $name = strtolower($name);

        // Truncate at the FIRST bracket rather than stripping trailing levels
        // with a regex. `(?:\[[^\]]*\])+$` is quadratic on a name that opens
        // brackets and never closes them — 20k of them took 67ms and 100k over
        // a second, with no PCRE error to trip the fail-closed guard, so it
        // was a denial of service reachable from a query string. The root name
        // is what matters anyway: `token[a][b]` is `token`, and
        // `filters[postal_code]` is `filters`.
        $bracket = strpos($name, '[');

        if ($bracket !== false) {
            $name = substr($name, 0, $bracket);
        }

        // `-` and `.` separate a name the way `_` does, so a header-ish
        // spelling counts: `x-api-key` is `x_api_key`.
        $name = strtr($name, ['-' => '_', '.' => '_']);

        // And so does a capital. `accessToken` is the same parameter as
        // `access_token` to every application that reads it, and was not
        // to this function — which mattered because camelCase is what a
        // JavaScript-facing API uses, and those are exactly the URLs an
        // application does not control.
        //
        // Done on the ORIGINAL spelling, since $name is lowercased above.
        $name = self::splitCamelCase($name, $original);

        if ($allowAmbiguous) {
            foreach (self::AMBIGUOUS_PARAMETERS as $ambiguous) {
                if ($name === $ambiguous) {
                    return true;
                }

                // As a suffix ONLY behind a qualifier that settles it.
                // `private_key` is a credential and `sort_key` is a sort
                // order; `client_code` is an OAuth code and `postal_code`
                // is an address. Matching every suffix caught all four,
                // which is how the first version of this broke a test
                // written to protect exactly those two.
                if (! str_ends_with($name, '_'.$ambiguous)) {
                    continue;
                }

                $qualifier = substr($name, 0, -strlen($ambiguous) - 1);

                if (in_array($qualifier, self::CREDENTIAL_QUALIFIERS, true)) {
                    return true;
                }
            }
        }

        foreach (self::CREDENTIAL_PARAMETERS as $credential) {
            if ($name === $credential || str_ends_with($name, '_'.$credential)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The issuer prefixes as one anchored pattern, built on first use.
     *
     * Memoised on the instance rather than statically: the list is
     * configurable, so two applications in one process — a test suite —
     * must not share one.
     */
    private function prefixPattern(): string
    {
        if ($this->prefixPattern !== null) {
            return $this->prefixPattern;
        }

        $prefixes = array_filter($this->credentialPrefixes, static fn (string $p): bool => $p !== '');

        // Matching nothing, for an empty list: `(?!)` always fails, and
        // an empty alternation would match everything.
        return $this->prefixPattern = $prefixes === []
            ? '/(?!)/'
            : '/^(?:'.implode('|', array_map(static fn (string $p): string => preg_quote($p, '/'), $prefixes)).')/';
    }

    /**
     * A parameter value as the receiving application reads it: quotes
     * off, percent-decoded. `sk%5Flive%5F…` is the same secret as
     * `sk_live_…` and matches no prefix without this.
     */
    private static function decodedValue(string $value): string
    {
        $value = trim($value, '"\'');

        return str_contains($value, '%') ? rawurldecode($value) : $value;
    }

    /**
     * Vendor prefixes that are a credential and nothing else.
     *
     * A name list cannot help here. `?t=`, `?k=`, `?sas=` and every
     * other parameter a third-party API invented are unknowable in
     * advance, and auto-instrumentation records URLs the application did
     * not write. What IS knowable is what the secret looks like: every
     * one of these issuers stamps its keys so they can be found in a
     * leak, which works just as well for finding them before one.
     *
     * @var list<string>
     */
    private const DEFAULT_CREDENTIAL_PREFIXES = [
        'sk_live_', 'sk_test_', 'rk_live_', 'rk_test_', 'whsec_',   // Stripe
        'ghp_', 'gho_', 'ghu_', 'ghs_', 'ghr_', 'github_pat_',      // GitHub
        'glpat-', 'gldt-',                                          // GitLab
        'xoxb-', 'xoxp-', 'xoxa-', 'xoxr-', 'xoxs-', 'xapp-',       // Slack
        'AKIA', 'ASIA',                                             // AWS
        'AIza',                                                     // Google
        'SG.',                                                      // SendGrid
        'shpat_', 'shpss_',                                         // Shopify
        'npm_', 'dop_v1_', 'doo_v1_', 'dor_v1_',                    // npm, DigitalOcean
        'sq0atp-', 'sq0csp-',                                       // Square
        'ya29.',                                                    // Google OAuth
    ];

    /**
     * @return list<string>
     */
    public static function defaultCredentialPrefixes(): array
    {
        return self::DEFAULT_CREDENTIAL_PREFIXES;
    }

    /**
     * Does this VALUE look like a credential, whatever it was called?
     *
     * The second half of the model, and the half that matters for
     * auto-instrumentation: a name list only catches parameters someone
     * thought of, and the URLs an application calls are full of ones
     * nobody did.
     *
     * Two rules, deliberately narrow, because over-redaction is its own
     * failure — an operator who cannot read the URLs stops trusting the
     * tool:
     *
     *  - A known issuer prefix. Unambiguous, and free to check.
     *  - Length with mixed character classes: 24+ characters containing
     *    lower, upper AND digits, which is what a generated token looks
     *    like and what a slug, an email, a date, a path or a sentence
     *    does not. A UUID is excluded by name — hex and hyphens only,
     *    never mixed case — because a UUID in a URL is almost always an
     *    id somebody needs to read.
     */
    public function valueLooksLikeCredential(string $value): bool
    {
        $length = strlen($value);

        if ($length < 8) {
            return false;
        }

        // One anchored alternation, compiled once, rather than a
        // str_starts_with per prefix: twenty-five of those ran for every
        // parameter of every URL on every span, and measured 9µs a span
        // — more than a third of the whole redaction pass, to check a
        // list that almost never matches.
        if (preg_match($this->prefixPattern(), $value) === 1) {
            return true;
        }

        if (! $this->valueShape || $length < $this->valueMinLength) {
            return false;
        }

        // A UUID is an identifier, not a secret, and it is the single
        // most common long value in a real query string.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1) {
            return false;
        }

        // Anything with a space, or a character no token generator emits,
        // is prose or a path rather than a credential.
        if (preg_match('/^[A-Za-z0-9._~+\/=-]+$/', $value) !== 1) {
            return false;
        }

        return preg_match('/[a-z]/', $value) === 1
            && preg_match('/[A-Z]/', $value) === 1
            && preg_match('/[0-9]/', $value) === 1;
    }

    /**
     * `accessToken` → `access_token`, using the original spelling to see
     * the capitals that lowercasing has already removed.
     *
     * Only when the lowercased name has no separators of its own: a name
     * that already says `x-api-key` needs nothing, and re-splitting
     * `AWSAccessKeyId` into `a_w_s_access_key_id` would be worse than
     * leaving it — which is why runs of capitals stay together.
     */
    private static function splitCamelCase(string $name, string $original): string
    {
        if (str_contains($name, '_') || $original === strtolower($original)) {
            return $name;
        }

        $split = preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '_', $original);

        return is_string($split) ? strtolower($split) : $name;
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
            // NOTE: the two query-parameter patterns that used to live here
            // are gone. Matching a parameter by literal text could never see
            // that `%74oken=` and `token%5B%5D=` are the same secret, and a
            // second pass over an already-replaced value re-matched it — with
            // a replacement containing a space, appending its own tail each
            // time. redactEncodedParameters() does the job by DECODED name
            // instead, on every attribute value, and is idempotent.
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

            // Names are free text too. `Telemetry::span()` and
            // `nameRequestSpansUsing()` both take whatever the app hands them,
            // and a name built from a URL carries its query with it — so the
            // same credential went out scrubbed in the attributes and verbatim
            // in the name beside them. The name only changes when there is
            // something in it to change.
            $span->updateName($this->value('span.name', $span->name));

            $span->replaceEvents(array_map(
                fn (SpanEvent $event): SpanEvent => new SpanEvent(
                    $this->value('event.name', $event->name),
                    $event->timeUnixNano,
                    $this->attributes($event->attributes),
                ),
                $span->events(),
            ));

            // A link's attributes reach the exporter like any others and were
            // the one set redaction never walked. A retried job's link to its
            // previous attempt carries whatever the app put on it.
            $span->replaceLinks(array_map(
                fn (SpanLink $link): SpanLink => new SpanLink($link->traceId, $link->spanId, $this->attributes($link->attributes)),
                $span->links(),
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
