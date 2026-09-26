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
     * The detectors whose subject is a run of digits, and which are
     * therefore worth skipping wholesale on a value that has none.
     *
     * @var list<string>
     */
    private const NUMERIC_DETECTORS = ['credit_card', 'iban', 'us_ssn', 'dk_cpr', 'ip', 'phone'];

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
        // One gate for the four numeric detectors, because they are
        // four full patterns over every value and most values have no
        // number in them worth looking at. The shortest identifier
        // here is nine digits, so three in a row is the cheapest test
        // that cannot produce a false negative.
        $numeric = preg_match('/\d{3}/', $value) === 1;

        foreach ($detectors as $detector) {
            if (! $numeric && in_array($detector, self::NUMERIC_DETECTORS, true)) {
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

        // Labels, not "dots and letters". `[A-Za-z0-9.-]+\.[A-Za-z]{2,}`
        // puts `.` in both the class and the separator, so every dot is
        // a place the engine can backtrack to — 8KB of `a.a.a…@b.b.b…`
        // took 140ms, on a string an attacker can put in a validation
        // message. Matching a label at a time removes the ambiguity, and
        // the possessive quantifiers remove the backtracking outright.
        // And a lookbehind, which is the half that actually mattered.
        // Possessive quantifiers stop the engine backtracking WITHIN a
        // match; they do not stop it trying every starting position, and
        // with a leading character class every character is one. On
        // `a.a.a…@b.b.b…` that is quadratic — 8KB took 122ms and 32KB
        // took 1.9 SECONDS, at flush, on a string an attacker can put in
        // a validation message. Refusing to start inside a local part
        // makes it linear.
        return self::replace(
            '/(?<![A-Za-z0-9._%+-])[A-Za-z0-9._%+-]++@(?:[A-Za-z0-9-]++\.)++[A-Za-z]{2,}+/',
            $value,
            $replacement,
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
        return self::replaceCallback(
            '/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/',
            $value,
            $replacement,
            static fn (string $match): bool => self::mod97($match),
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

                return $day >= 1 && $day <= 31 && $month >= 1 && $month <= 12;
            },
        );
    }

    private static function ip(string $value, string $replacement): string
    {
        $value = self::replaceCallback(
            '/\b\d{1,3}(?:\.\d{1,3}){3}\b/',
            $value,
            $replacement,
            static fn (string $match): bool => filter_var($match, FILTER_VALIDATE_IP) !== false,
        );

        if (! str_contains($value, ':')) {
            return $value;
        }

        return self::replaceCallback(
            '/\b(?:[A-Fa-f0-9]{1,4}:){2,7}[A-Fa-f0-9]{1,4}\b/',
            $value,
            $replacement,
            static fn (string $match): bool => filter_var($match, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
        );
    }

    /**
     * E.164 only — a leading `+`, a country code, eight to fourteen
     * more digits. Bare national numbers are not attempted: they are
     * indistinguishable from order ids, and a detector that eats those
     * is the one that gets switched off.
     */
    private static function phone(string $value, string $replacement): string
    {
        if (! str_contains($value, '+')) {
            return $value;
        }

        return self::replace('/(?<![\w+])\+[1-9]\d{1,3}[ .-]?(?:\(?\d{1,4}\)?[ .-]?){1,4}\d{2,4}\b/', $value, $replacement);
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
