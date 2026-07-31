<?php

namespace App\Services\Platforms;

// The single source of truth for "a non-empty trimmed string, or null" across
// the menu/scrape pipeline (#INH-6) — previously seven private copies under
// three names (cleanString, and MenuMerger::str).
//
// NOT the same helper as BaseFormRequest::cleanString(), which additionally
// strips <script>/<style> blocks, runs strip_tags() and removes control
// characters. That one is request-sanitisation and stays where it is.
trait CleansScrapedStrings
{
    /**
     * A non-empty trimmed string, or null.
     *
     * `mixed` on purpose. Two consumers (MenuContentController, MenuScanApplier)
     * previously declared `?string` while being fed raw array values — validated
     * request payloads in most paths, but MenuFetchJob hands MenuScanApplier the
     * menus.scan_items JSONB column, which no validator guards across the
     * round-trip. Under weak typing a non-string there either coerced to garbage
     * (42 → "42") or fatalled outright (an array → TypeError). Accepting `mixed`
     * and returning null is the behaviour every other copy already had.
     */
    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $s = trim($value);

        return $s !== '' ? $s : null;
    }
}
