<?php

namespace App\Services\Platforms;

/**
 * Careful title-casing for SCANNED/OCR'd names (menu deep-links plan B5,
 * absorbed backend-fixes item 3a, 2026-08-26). Menu photo OCR, the
 * old-website HTML scan and the services scrapers emit ALL-CAPS
 * ("EXPRESS LUNCH", "STRAWBERRY ICED MATCHA LATTE") or all-lowercase names;
 * owners want title case.
 *
 * THE one guard that makes this safe to run blind: only a UNIFORMLY cased
 * string (all-upper or all-lower) is re-cased — mixed case means the vendor
 * typed it deliberately, and it passes through untouched. Ordering-platform
 * scrape names (Uber/DoorDash/Square) are deliberately OUT of scope:
 * marketplace names are usually deliberately cased.
 *
 * Transform rules:
 *  - capitalize each word; connector words (of, and, the, with, on, in, a, &)
 *    stay lowercase MID-name — first and last word always capitalize;
 *  - an UPPERCASE allowlist survives: AU state abbreviations and dietary
 *    marks — the list lives in ScrapedNameCasing, shared with the menu-driver
 *    re-caser so the two cannot drift apart again;
 *  - unit tokens pass through untouched (1.2L, 225g, 7pk, 2L);
 *  - applied at PROJECTION/WRITE time, not display — slugs, the matcher and
 *    the wire all see the clean name. Item AND category names.
 */
trait CasesScannedNames
{
    private function scanTitleCase(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }
        $name = trim($name);
        if ($name === '' || ! $this->isUniformlyCased($name)) {
            return $name === '' ? null : $name;
        }

        $words = preg_split('/(\s+)/u', $name, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$name];
        // Indices of actual words (not whitespace separators), to know
        // first/last for the always-capitalize rule.
        $wordIndexes = [];
        foreach ($words as $i => $token) {
            if (trim($token) !== '') {
                $wordIndexes[] = $i;
            }
        }
        $first = $wordIndexes[0] ?? null;
        $last = $wordIndexes[count($wordIndexes) - 1] ?? null;

        foreach ($wordIndexes as $i) {
            $words[$i] = $this->scanCaseWord($words[$i], $i === $first || $i === $last);
        }

        return implode('', $words);
    }

    private function scanCaseWord(string $word, bool $edge): string
    {
        // Unit tokens (1.2L, 225g, 7pk, 2L) — digits followed by a unit —
        // pass through exactly as scanned.
        if (preg_match('/^\d/', $word) === 1) {
            return $word;
        }

        $upper = mb_strtoupper($word);
        // The allowlist token keeps (or gains) its canonical uppercase form —
        // "'23 deep woods chardonnay wa" and "…CHARDONNAY WA" both keep "WA".
        if (in_array(rtrim($upper, '.,'), ScrapedNameCasing::ALL_CAPS_MARKS, true)) {
            return rtrim($upper, '.,').substr($word, strlen(rtrim($word, '.,')));
        }

        $lower = mb_strtolower($word);
        if (! $edge && in_array(rtrim($lower, '.,'), ScrapedNameCasing::CONNECTORS, true)) {
            return $lower;
        }

        // Capitalize each hyphen/slash-joined part ("choc-chip" → "Choc-Chip").
        return preg_replace_callback(
            '/(^|[-\/(])(\p{L})/u',
            fn (array $m) => $m[1].mb_strtoupper($m[2]),
            $lower,
        ) ?? ucfirst($lower);
    }

    /**
     * All-upper or all-lower (ignoring digits/punctuation). Mixed case is the
     * vendor's deliberate choice; a string with no letters at all is not
     * "uniform" — there is nothing to re-case.
     */
    private function isUniformlyCased(string $name): bool
    {
        $hasUpper = preg_match('/\p{Lu}/u', $name) === 1;
        $hasLower = preg_match('/\p{Ll}/u', $name) === 1;

        return ($hasUpper && ! $hasLower) || ($hasLower && ! $hasUpper);
    }
}
