<?php

namespace App\Services\Platforms;

// Shared scrape-output cleanup helpers used by every MenuPlatformDriver's
// mapItems(). Moved verbatim off MenuApifyScraper (FOUND-23) — byte-identical
// behavior, just relocated so both drivers can use them.
trait NormalizesMenuData
{
    /** A non-empty trimmed string, or null. */
    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $s = trim($value);

        return $s !== '' ? $s : null;
    }

    /** Title Case — first letter of every word uppercase, rest lowercase. */
    private function titleCase(?string $s): ?string
    {
        if ($s === null) {
            return null;
        }

        return ucwords(strtolower($s));
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
