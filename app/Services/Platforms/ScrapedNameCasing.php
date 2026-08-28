<?php

namespace App\Services\Platforms;

/**
 * The shared casing vocabulary for scraped names, plus the two predicates that
 * decide when a source's own typography must survive re-casing.
 *
 * Two traits re-case scraped names and they gate at DIFFERENT altitudes, on
 * purpose:
 *  - CasesScannedNames::scanTitleCase() gates on the WHOLE STRING — menu-photo
 *    OCR and old-website scans emit a uniformly cased line, so mixed case means
 *    a human typed it and the line passes through untouched.
 *  - NormalizesMenuData::titleCase() gates on the TOKEN, because an
 *    ordering-platform payload mixes both inside one name: "Cold Brew Bags.
 *    (Italo concentrate 1.2l)" was serving live, and a whole-string gate would
 *    leave "concentrate" lowercase.
 *
 * What must NOT differ between them is the vocabulary — which marks survive,
 * and which connectors drop to lowercase mid-name. Those lived in two places
 * and drifted; they live here once (2026-08-28).
 */
final class ScrapedNameCasing
{
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
