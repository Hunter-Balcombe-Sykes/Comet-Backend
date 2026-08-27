<?php

namespace App\Services\Platforms;

// Shared scrape-output cleanup helpers used by every MenuPlatformDriver's
// mapItems(). Moved verbatim off MenuApifyScraper (FOUND-23) — byte-identical
// behavior, just relocated so both drivers can use them.
trait NormalizesMenuData
{
    // cleanString() lives in CleansScrapedStrings (#INH-6) — the drivers still
    // get it from here, it just is not a seventh copy of the same six lines.
    use CleansScrapedStrings;

    /**
     * Title Case for scraped item names. T8 (2026-08-27): the bare
     * ucwords(strtolower()) shipped "Cold Brew/oat Latte Can",
     * "Cold Brew Bags. (italo Concentrate 1.2l)" and "Cronut." to live
     * menus. Now: capitalises after '/', '(', '-' and whitespace; strips
     * trailing periods (incl. one straggling before an opening paren);
     * preserves short ALL-CAPS tokens (VIC, WA, GF) when the source is
     * mixed-case (an all-caps source has no signal to preserve); uppercases
     * a lone litre unit after digits ("1.2l" → "1.2L").
     */
    private function titleCase(?string $s): ?string
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

        // Preserve short all-caps tokens only when the string is MIXED case —
        // "LAMB RAGU" carries no signal, "'23 Deep Woods Chardonnay WA" does.
        $mixed = preg_match('/[a-z]/', $s) === 1;
        $keep = [];
        if ($mixed) {
            preg_match_all('/\b[A-Z]{2,3}\b/', $s, $m);
            $keep = array_unique($m[0]);
        }

        $out = ucwords(strtolower($s), " \t\r\n\f\v/-(");

        foreach ($keep as $token) {
            $out = preg_replace('/\b'.preg_quote(ucfirst(strtolower($token)), '/').'\b/', $token, $out) ?? $out;
        }

        // "1.2l" → "1.2L" — a lone litre unit riding a number.
        return preg_replace('/(?<=\d)l\b/', 'L', $out) ?? $out;
    }

    /** Sentence case — first letter of each sentence uppercase, rest lowercase. */
    private function sentenceCase(?string $s): ?string
    {
        if ($s === null) {
            return null;
        }

        $lower = strtolower($s);

        return preg_replace_callback(
            '/(^|\.\s+|!\s+|\?\s+)([a-z])/u',
            fn (array $m) => $m[1].strtoupper($m[2]),
            $lower,
        ) ?? $lower;
    }

    /** A trimmed http/https URL, or null — drops javascript:/data:/relative etc. */
    private function safeUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $url = trim($value);

        return preg_match('~^https?://~i', $url) === 1 ? $url : null;
    }
}
