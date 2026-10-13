<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

/**
 * The shape of a query: its text with every value taken out.
 *
 * Laravel's own queries are parameterized already, but "already" is not
 * "always": `whereRaw('id = 5')`, `DB::select("... = 'x'")` and
 * `whereIn()` all put values or a value COUNT into the text. A whereIn
 * over 3 ids and one over 4 are the same query — `in (?, ?, ?)` and
 * `in (?, ?, ?, ?)` are not the same string. Grouping by raw text splits
 * one N+1 into as many groups as there are list lengths.
 *
 * So: string and numeric literals become `?`, any `in (…)` list becomes
 * `in (?)`, multi-row `values (…), (…)` becomes one row, and whitespace
 * collapses. Identifiers are left alone — `"orders2"` and `t1.id` keep
 * their digits, because they are names, not values.
 *
 * A regex pass, not a parser. It runs per query, and a fingerprint that
 * is occasionally too coarse costs a slightly wrong group; a parser costs
 * every query.
 */
final class SqlShape
{
    public static function normalize(string $sql): string
    {
        $shape = preg_replace(
            [
                // Quoted string literals, with '' and \' escapes.
                "/'(?:[^'\\\\]|\\\\.|'')*'/s",
                // Numeric literals that are not part of an identifier:
                // not preceded by a word char, quote, dot, $ or : (bindings).
                '/(?<![\w"`.$:\]])-?\d+(?:\.\d+)?(?:e[+-]?\d+)?\b/i',
                // Whitespace.
                '/\s+/',
            ],
            ['?', '?', ' '],
            $sql,
        );

        if (! is_string($shape)) {
            return $sql;
        }

        $collapsed = preg_replace(
            [
                // in (?, ?, ?) / in (:a, :b) → in (?)
                '/\bin\s*\(\s*(?:\?|:\w+)(?:\s*,\s*(?:\?|:\w+))*\s*\)/i',
                // values (?, ?), (?, ?) → values (?, ?)
                '/(\bvalues\s*\([^()]*\))(?:\s*,\s*\([^()]*\))+/i',
            ],
            ['in (?)', '$1'],
            $shape,
        );

        return trim(is_string($collapsed) ? $collapsed : $shape);
    }

    /**
     * A short, stable id for a shape on one connection — 16 hex chars,
     * the same width as a span id, so a UI can group by it cheaply.
     */
    public static function hash(string $connection, string $sql): string
    {
        return hash('xxh3', $connection.'|'.self::normalize($sql));
    }

    /**
     * Whether a statement only reads. N+1 is a read pattern — a loop of
     * inserts is a different smell (a missing bulk insert) with a
     * different fix, and naming it N+1 would send someone to look for
     * an eager load that cannot help.
     */
    public static function isRead(string $sql): bool
    {
        $head = strtolower(substr(ltrim($sql, " \t\n\r(/"), 0, 6));

        return str_starts_with($head, 'select') || str_starts_with($head, 'with');
    }
}
