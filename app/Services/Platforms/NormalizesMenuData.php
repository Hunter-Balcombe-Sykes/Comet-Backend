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
     * "Cold Brew Bags. (italo Concentrate 1.2l)" and "Cronut." to live menus.
     * Now: capitalises after '/', '(', '-' and whitespace; strips trailing
     * periods (incl. one straggling before an opening paren); uppercases a
     * lone litre unit after digits ("1.2l" → "1.2L"); and leaves alone any
     * TOKEN the source cased deliberately — see caseMenuToken().
     *
     * The gate is per token, not per string (2026-08-28). T8 gated ALL-CAPS
     * preservation on the whole string being mixed-case, which disarmed it on
     * exactly the input that needs it — an all-caps scraped wine list — so
     * "'23 DEEP WOODS CHARDONNAY WA" served "…Chardonnay Wa", the very
     * example this docblock claimed to handle. A whole-string gate cannot be
     * the answer either: "Cold Brew Bags. (Italo concentrate 1.2l)" is mixed
     * AND needs re-casing. The signal is a property of the token.
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

        $out = preg_replace_callback(
            '/\p{L}+/u',
            function (array $m) use ($s) {
                [$run, $offset] = $m[0];

                // Typography the source meant: an interior capital ("McDonalds",
                // "iPhone"), an allowlisted all-caps mark ("WA", "(GF)(V)"), or a
                // short caps run in a string that also carries lowercase ("Cold
                // Brew CAN"). None survives a lowercase-then-capitalise pass, so
                // all three are answered before it.
                if (ScrapedNameCasing::hasInteriorCapital($run)
                    || ScrapedNameCasing::isPreservedAllCapsMark($run)
                    || ScrapedNameCasing::isDeliberateAllCapsRun($run, $s)) {
                    return $run;
                }

                $run = mb_strtolower($run);

                // ucwords()'s old delimiter set, kept exactly: a run capitalises
                // at the start of the name or after '/', '-' or '('. An
                // apostrophe is NOT a boundary — "O'brien's", as before. A
                // multibyte character's trailing byte can never match one of
                // these, which is the right answer for punctuation anyway.
                $boundary = $offset === 0 || strpos(" \t\r\n\f\v/-(", $s[$offset - 1]) !== false;

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
