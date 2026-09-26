<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

/**
 * Structured personal identifiers, found in free text.
 *
 * WHAT THIS IS, AND IS NOT
 *
 * It is a defence-in-depth control: it catches identifiers that leaked
 * into telemetry by accident — an email in an exception message, a card
 * number in a SQL string, a CPR in a URL path — which is how personal
 * data usually arrives in an observability pipeline. Nobody decides to
 * put it there.
 *
 * It is not compliance, and no configuration of it makes an application
 * GDPR-, HIPAA- or SOC 2-compliant. Those are organisational, and most
 * of what they cover cannot be pattern-matched at all: HIPAA names
 * eighteen identifiers and this can see six of them, because a person's
 * name, their address and a medical record number have no form to
 * recognise. Those need the key-based rules and the discipline not to
 * put them in a span in the first place.
 *
 * WHY EVERY DETECTOR HAS A CHECKSUM WHERE ONE EXISTS
 *
 * A sixteen-digit number is an order id far more often than it is a
 * card. Without the Luhn check this pass would redact the primary key
 * of every table in the application and an operator would switch the
 * whole thing off within a day — which is worse than never having
 * shipped it. Precision is the feature; a detector that cries wolf
 * removes more data than it protects.
 *
 * Each detector is gated by a cheap test before its pattern runs,
 * because this walks every attribute value of every span at flush.
 */
final class PersonalData
{
    /**
     * The fewest digits a value must contain before a detector can
     * possibly match, so the expensive pattern is skipped on values
     * that cannot hold its subject.
     *
     * Per detector, not one shared gate. The shared `\d{3}` gate was
     * wrong in both directions: it skipped `41 11 11 11 11 11 11 11`,
     * a perfectly valid card with separators and never three digits in
     * a row, and it ran the IPv4 matcher on values with nine digits
     * and no dots. Counting digits once is linear and cannot produce
     * either mistake.
     *
     * @var array<string, int>
     */
    private const MINIMUM_DIGITS = [
        'credit_card' => 13,
        'iban' => 12,
        'dk_cpr' => 10,
        'us_ssn' => 9,
        'phone' => 5,
        'ip' => 4,
    ];

    /**
     * The built-in detectors, in the order they run.
     *
     * `ip` is deliberately not on by default even though the GDPR
     * treats an address as personal data: an IP is also how you find
     * the one host that is broken, and removing it from everything
     * blinds the tool. Switch it on where the obligation is real.
     *
     * @return list<string>
     */
    public static function defaultDetectors(): array
    {
        return ['email', 'credit_card', 'iban', 'us_ssn', 'dk_cpr'];
    }

    /**
     * Every detector this class knows, including those off by default.
     *
     * @return list<string>
     */
    public static function availableDetectors(): array
    {
        return ['email', 'credit_card', 'iban', 'us_ssn', 'dk_cpr', 'ip', 'phone'];
    }

    /**
     * Replace every identifier the named detectors recognise.
     *
     * @param  list<string>  $detectors
     */
    public static function scrub(string $value, array $detectors, string $replacement): string
    {
        // One linear count, then a per-detector threshold. Most values
        // have no digits at all and skip every numeric detector on a
        // single integer comparison.
        $digits = preg_match_all('/\d/', $value) ?: 0;

        foreach ($detectors as $detector) {
            if ($digits < (self::MINIMUM_DIGITS[$detector] ?? 0)) {
                continue;
            }

            $value = match ($detector) {
                'email' => self::email($value, $replacement),
                'credit_card' => self::creditCard($value, $replacement),
                'iban' => self::iban($value, $replacement),
                'us_ssn' => self::usSsn($value, $replacement),
                'dk_cpr' => self::dkCpr($value, $replacement),
                'ip' => self::ip($value, $replacement),
                'phone' => self::phone($value, $replacement),
                default => $value,
            };
        }

        return $value;
    }

    private static function email(string $value, string $replacement): string
    {
        if (! str_contains($value, '@')) {
            return $value;
        }

        // The domain is matched as labels with no trailing dot, and
        // whether it ends in a real TLD is decided in PHP rather than
        // by the pattern. A possessive `(?:label\.)++[A-Za-z]{2,}`
        // consumed the final dot of `alice@example.com.` and could not
        // give it back, so an address at the end of a sentence went
        // straight through.
        //
        // The lookbehind is what keeps this linear: possessive
        // quantifiers stop backtracking within a match, not the engine
        // trying every starting position, and with a leading character
        // class every character is one.
        return self::replaceCallback(
            '/(?<![A-Za-z0-9._%+-])[A-Za-z0-9._%+-]++@[A-Za-z0-9-]++(?:\.[A-Za-z0-9-]++)*+/',
            $value,
            $replacement,
            static function (string $match): bool {
                $domain = substr($match, strrpos($match, '@') + 1);
                $tld = substr($domain, (int) strrpos($domain, '.') + 1);

                return str_contains($domain, '.') && strlen($tld) >= 2 && ctype_alpha($tld);
            },
        );
    }

    /**
     * Thirteen to nineteen digits that pass Luhn, with the separators a
     * human types. The checksum is the whole point: without it this is
     * a detector for "long number", and long numbers are what an
     * application is made of.
     */
    private static function creditCard(string $value, string $replacement): string
    {
        return self::replaceCallback(
            '/\b(?:\d[ -]?){12,18}\d\b/',
            $value,
            $replacement,
            static fn (string $match): bool => self::luhn($match),
        );
    }

    /**
     * IBAN, validated mod-97. Two letters, two check digits, then up to
     * thirty alphanumerics — a shape that otherwise matches plenty of
     * reference numbers.
     */
    private static function iban(string $value, string $replacement): string
    {
        // Spaces and lower case, because that is how an IBAN is
        // written down by a human and pasted into a form — the
        // compact-uppercase-only matcher missed both
        // `DK50 0040 0440 1162 43` and `dk5000400440116243`.
        return self::replaceCallback(
            // The two forms an IBAN is legally written in, spelled
            // out rather than one loose pattern: an optional space
            // inside the repetition ate the separator and ran on into
            // the next word, so `DK5000400440116243 failed` became one
            // candidate that failed mod-97 and redacted nothing.
            '/\b[A-Za-z]{2}\d{2}(?:[A-Za-z0-9]{10,30}|(?:[ ][A-Za-z0-9]{4}){2,7}(?:[ ][A-Za-z0-9]{1,3})?)\b/',
            $value,
            $replacement,
            static fn (string $match): bool => self::mod97(strtoupper(str_replace(' ', '', $match))),
        );
    }

    /**
     * A US social security number, excluding the ranges the SSA never
     * issues — 000, 666 and 900+ in the area, 00 in the group, 0000 in
     * the serial — because `123-45-6789` in a fixture should not be a
     * finding and `000-00-0000` is a placeholder.
     */
    private static function usSsn(string $value, string $replacement): string
    {
        return self::replace(
            '/\b(?!000|666|9\d\d)\d{3}-(?!00)\d{2}-(?!0000)\d{4}\b/',
            $value,
            $replacement,
        );
    }

    /**
     * A Danish CPR number: a date, then four digits. The mod-11 check
     * is NOT applied — it was abandoned in 2007 and numbers issued
     * since legitimately fail it — so the date is what carries the
     * precision.
     */
    private static function dkCpr(string $value, string $replacement): string
    {
        return self::replaceCallback(
            '/\b(\d{2})(\d{2})(\d{2})-?\d{4}\b/',
            $value,
            $replacement,
            static function (string $match): bool {
                $day = (int) substr($match, 0, 2);
                $month = (int) substr($match, 2, 2);
                $year = (int) substr($match, 4, 2);

                // checkdate, not a range check: `310299` passed a
                // day<=31 test and February has never had 31 days.
                // Both centuries, because the number carries only two
                // digits of year and a leap day is valid in one of
                // them — 2000 was a leap year, 1900 was not.
                return checkdate($month, $day, 2000 + $year) || checkdate($month, $day, 1900 + $year);
            },
        );
    }

    private static function ip(string $value, string $replacement): string
    {
        $value = self::replaceCallback(
            '/\b\d{1,3}(?:\.\d{1,3}){3}\b/',
            $value,
            $replacement,
            static fn (string $match): bool => filter_var($match, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
        );

        if (! str_contains($value, ':')) {
            return $value;
        }

        // Compressed forms too: `2001:db8::1` has no run of at least
        // two groups on both sides of the `::` and the old pattern
        // required one, so the shortest and most common way of writing
        // an IPv6 address was the one it missed. Candidates are loose
        // and filter_var is the authority, which is the same division
        // of labour the checksummed detectors use.
        return self::replaceCallback(
            '/(?<![:.\w])(?:[A-Fa-f0-9]{0,4}:){2,7}[A-Fa-f0-9]{0,4}(?![:.\w])/',
            $value,
            $replacement,
            static fn (string $match): bool => filter_var($match, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
        );
    }

    /**
     * E.164 only — a `+`, a country code, and eight to fourteen more
     * digits. Bare national numbers are not attempted: they are
     * indistinguishable from order ids, and a detector that eats those
     * is the one that gets switched off.
     */
    private static function phone(string $value, string $replacement): string
    {
        if (! str_contains($value, '+')) {
            return $value;
        }

        // Atomic, and the length checked in PHP. The old pattern's
        // `(?:\(?\d{1,4}\)?[ .-]?){1,4}` backtracked hard on a near
        // miss — 9ms on a single value of repeated `+1234567890…x`,
        // which an attacker can put in as many attributes as they like
        // — and it matched `+12345`, which is shorter than the eight
        // digits its own docblock promised.
        return self::replaceCallback(
            '/(?<![\w+])\+[1-9][\d .-]{7,20}\d/',
            $value,
            $replacement,
            static function (string $match): bool {
                $digits = preg_match_all('/\d/', $match) ?: 0;

                return $digits >= 9 && $digits <= 15;
            },
        );
    }

    private static function replace(string $pattern, string $value, string $replacement): string
    {
        $scrubbed = preg_replace($pattern, $replacement, $value);

        // Fail closed, as the rest of the engine does: the one value
        // long enough to defeat the matcher must not be the one value
        // that escapes it.
        if (! is_string($scrubbed)) {
            return preg_last_error() !== PREG_NO_ERROR ? $replacement : $value;
        }

        return $scrubbed;
    }

    /**
     * @param  callable(string): bool  $verify
     */
    private static function replaceCallback(string $pattern, string $value, string $replacement, callable $verify): string
    {
        $scrubbed = preg_replace_callback(
            $pattern,
            static fn (array $m): string => $verify($m[0]) ? $replacement : $m[0],
            $value,
        );

        if (! is_string($scrubbed)) {
            return preg_last_error() !== PREG_NO_ERROR ? $replacement : $value;
        }

        return $scrubbed;
    }

    private static function luhn(string $candidate): bool
    {
        $digits = preg_replace('/\D/', '', $candidate) ?? '';
        $length = strlen($digits);

        if ($length < 13 || $length > 19) {
            return false;
        }

        $sum = 0;
        $double = false;

        for ($i = $length - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];

            if ($double) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }

    private static function mod97(string $candidate): bool
    {
        $rearranged = substr($candidate, 4).substr($candidate, 0, 4);
        $numeric = '';

        foreach (str_split($rearranged) as $character) {
            $numeric .= ctype_alpha($character)
                ? (string) (ord(strtoupper($character)) - 55)
                : $character;
        }

        // Piecewise, because an IBAN is longer than an integer.
        $remainder = 0;

        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) (((string) $remainder).$chunk) % 97;
        }

        return $remainder === 1;
    }
}
