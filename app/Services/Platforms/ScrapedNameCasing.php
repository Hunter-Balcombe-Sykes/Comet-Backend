<?php

namespace App\Services\Platforms;

/**
 * The token-gated re-caser for scraped names, plus the shared casing
 * vocabulary and the three predicates that decide when a source's own
 * typography must survive re-casing.
 *
 * Two re-casers exist and they gate at DIFFERENT altitudes, on purpose:
 *  - CasesScannedNames::scanTitleCase() gates on the WHOLE STRING — menu-photo
 *    OCR and old-website scans emit a uniformly cased line, so mixed case means
 *    a human typed it and the line passes through untouched.
 *  - self::titleCase() gates on the TOKEN, because an ordering-platform or
 *    booking-platform payload mixes both inside one name: "Cold Brew Bags.
 *    (Italo concentrate 1.2l)" was serving live, and a whole-string gate would
 *    leave "concentrate" lowercase.
 *
 * titleCase() lives HERE rather than on NormalizesMenuData (2026-08-28)
 * because it is no longer menu-only: FreshaConnector::mapServiceItem() needs
 * it too, and an App\Ingest connector should not inherit a private method from
 * an App\Services\Platforms trait to get at one function. NormalizesMenuData
 * keeps a one-line delegate as the drivers' named seam. Before this there were
 * THREE implementations — FreshaConnector carried its own bare
 * ucwords(mb_strtolower()) (T11, 7ae3a223f), fixed one file over the same day
 * by T8 (7d19320ef) and never backported, so "Colour (Full Head)." shipped its
 * period to the public services page.
 *
 * What must NOT differ between the two remaining re-casers is the vocabulary —
 * which marks survive, and which connectors drop to lowercase mid-name. Both
 * live here once (2026-08-28), but that was only true of ALL_CAPS_MARKS until
 * 2026-09-02: CasesScannedNames::scanTitleCase() read CONNECTORS from the
 * start, while self::titleCase() below declared it and never read it, so the
 * ingest lane published "Just A Few Locs" and "Toner With Color" until this
 * class's own token loop was wired to it.
 */
final class ScrapedNameCasing
{
    /**
     * Title Case for scraped item names. T8 (2026-08-27): the bare
     * ucwords(strtolower()) shipped "Cold Brew/oat Latte Can",
     * "Cold Brew Bags. (italo Concentrate 1.2l)" and "Cronut." to live menus.
     * Now: capitalises after '/', '(', '-' and whitespace; strips trailing
     * periods (incl. one straggling before an opening paren); uppercases a
     * lone litre unit after digits ("1.2l" → "1.2L"); and leaves alone any
     * TOKEN the source cased deliberately — see hasInteriorCapital(),
     * isPreservedAllCapsMark() and isDeliberateAllCapsRun() below.
     *
     * The gate is per token, not per string (2026-08-28). T8 gated ALL-CAPS
     * preservation on the whole string being mixed-case, which disarmed it on
     * exactly the input that needs it — an all-caps scraped wine list — so
     * "'23 DEEP WOODS CHARDONNAY WA" served "…Chardonnay Wa", the very
     * example this docblock claimed to handle. A whole-string gate cannot be
     * the answer either: "Cold Brew Bags. (Italo concentrate 1.2l)" is mixed
     * AND needs re-casing. The signal is a property of the token.
     */
    public static function titleCase(?string $s): ?string
    {
        if ($s === null) {
            return null;
        }

        $s = trim($s);
        // "Cronut." / "Cookie (anzac)." → strip terminal periods; also the
        // straggler in "Cold Brew Bags. (…" — a period directly before an
        // opening paren is the source's own noise, never menu punctuation.
        $s = rtrim($s, '.');
        $s = preg_replace('/\.\s+\(/', ' (', $s) ?? $s;

        // First and last letter-run always capitalise, connector word or not.
        preg_match_all('/\p{L}+/u', $s, $runs, PREG_OFFSET_CAPTURE);
        $offsets = array_column($runs[0], 1);
        $firstOffset = $offsets[0] ?? -1;
        $lastOffset = $offsets === [] ? -1 : end($offsets);

        $out = preg_replace_callback(
            '/\p{L}+/u',
            function (array $m) use ($s, $firstOffset, $lastOffset) {
                [$run, $offset] = $m[0];

                // Typography the source meant: an interior capital ("McDonalds",
                // "iPhone"), an allowlisted all-caps mark ("WA", "(GF)(V)"), or a
                // short caps run in a string that also carries lowercase ("Cold
                // Brew CAN"). None survives a lowercase-then-capitalise pass, so
                // all three are answered before it.
                if (self::hasInteriorCapital($run)
                    || self::isPreservedAllCapsMark($run)
                    || self::isDeliberateAllCapsRun($run, $s)) {
                    return $run;
                }

                $run = mb_strtolower($run);
                $prev = $offset === 0 ? '' : $s[$offset - 1];

                // ucwords()'s old delimiter set, kept exactly: a run capitalises
                // at the start of the name or after '/', '-' or '('. An
                // apostrophe is NOT a boundary — "O'brien's", as before. A
                // multibyte character's trailing byte can never match one of
                // these, which is the right answer for punctuation anyway.
                $boundary = $offset === 0 || strpos(" \t\r\n\f\v/-(", $prev) !== false;

                // CONNECTORS — the vocabulary this class exists to share, which
                // titleCase() never actually read until 2026-09-02. Lowercase
                // only MID-name, only after whitespace, and only when the
                // preceding non-space character does not open a new clause.
                // A clause opener is one of '-', '(', '/', ',' or ':' — the
                // five characters in $opensClause's charset below.
                // "Manicure - With Gel Polish" keeps its capital because '-'
                // opens a clause; "Toner with Color" loses one because plain
                // whitespace does not; "Cut, and Colour" keeps "And" for the
                // same reason as '-' — the comma opens a fresh clause too.
                $afterSpace = $prev !== '' && strpos(" \t\r\n\f\v", $prev) !== false;
                $clauseHead = rtrim(substr($s, 0, $offset));
                $opensClause = $clauseHead !== '' && strpos('-(/,:', substr($clauseHead, -1)) !== false;
                $edge = $offset === $firstOffset || $offset === $lastOffset;

                if (! $edge && $afterSpace && ! $opensClause && in_array($run, self::CONNECTORS, true)) {
                    return $run;
                }

                return $boundary
                    ? mb_strtoupper(mb_substr($run, 0, 1)).mb_substr($run, 1)
                    : $run;
            },
            $s,
            flags: PREG_OFFSET_CAPTURE,
        ) ?? $s;

        // "1.2l" → "1.2L" — a lone litre unit riding a number.
        return preg_replace('/(?<=\d)l\b/', 'L', $out) ?? $out;
    }

    /** Uppercase tokens that survive re-casing: AU state abbreviations + dietary marks. */
    public const ALL_CAPS_MARKS = ['WA', 'VIC', 'NSW', 'QLD', 'SA', 'TAS', 'NT', 'ACT', 'GF', 'DF', 'V', 'VG'];

    /** Connector words that stay lowercase mid-name (first and last word always capitalise). */
    public const CONNECTORS = ['of', 'and', 'the', 'with', 'on', 'in', 'a', '&'];

    /**
     * An interior capital — the one casing signal a scrape cannot fake:
     * "McDonalds", "iPhone", "MacGyver's". A lowercase letter is required too,
     * so "LAMB" and "BBQ" are not read as deliberate typography.
     */
    public static function hasInteriorCapital(string $token): bool
    {
        $letters = preg_replace('/[^\p{L}]+/u', '', $token) ?? '';

        return preg_match('/\p{Ll}/u', $letters) === 1
            && preg_match('/\p{Lu}/u', mb_substr($letters, 1)) === 1;
    }

    /**
     * An all-caps run in a string that ALSO contains lowercase — the source's
     * own contrast is the proof it meant those capitals ("Cold Brew CAN (…)",
     * "OG Kimbap", both live). This is T8's original signal and it is real; it
     * was only ever incomplete, because an ALL-CAPS source has no contrast to
     * offer and so disarmed it entirely. Length stays bounded at 2-3 as T8 had
     * it: widening it is a separate behaviour change, not this one.
     */
    public static function isDeliberateAllCapsRun(string $run, string $whole): bool
    {
        return preg_match('/^\p{Lu}{2,3}$/u', $run) === 1
            && preg_match('/\p{Ll}/u', $whole) === 1;
    }

    /**
     * An ALREADY-uppercase allowlisted mark ("WA", "(GF)"). Preserve only,
     * never promote a lowercase "gf"/"v" — those are ordinary words far more
     * often than they are marks, and a scrape gives no way to tell apart.
     *
     * The set is an allowlist rather than a length rule because `[A-Z]{2,3}`
     * matches THE, HOT and RED exactly as happily as it matches WA.
     */
    public static function isPreservedAllCapsMark(string $token): bool
    {
        $letters = preg_replace('/[^\p{L}]+/u', '', $token) ?? '';

        return $letters !== '' && in_array($letters, self::ALL_CAPS_MARKS, true);
    }
}
