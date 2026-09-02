<?php

namespace App\Services\Shop;

/**
 * Reads a discount code out of a link tile's TITLE or description
 * ("Gamma+ - CODE: TEEGAN10", "use code TEEGAN10 for 10% off") — never out
 * of the URL. Pure; the importer decides what to do with the answer.
 */
final class DiscountCodeSniffer
{
    private const PATTERNS = [
        '/\bcode\s*[:\-–]?\s*([A-Z0-9][A-Z0-9\-]{2,19})\b/i',
        '/\buse\s+(?:the\s+)?code\s+([A-Z0-9][A-Z0-9\-]{2,19})\b/i',
        '/\bpromo(?:\s*code)?\s*[:\-–]?\s*([A-Z0-9][A-Z0-9\-]{2,19})\b/i',
        '/\bdiscount(?:\s*code)?\s*[:\-–]?\s*([A-Z0-9][A-Z0-9\-]{2,19})\b/i',
        '/\bcoupon(?:\s*code)?\s*[:\-–]?\s*([A-Z0-9][A-Z0-9\-]{2,19})\b/i',
    ];

    /** Words that follow "code" in prose without being a code. */
    private const NOT_CODES = ['for', 'at', 'on', 'to', 'the', 'your', 'off', 'and', 'with', 'is', 'in', 'here', 'now', 'below', 'above'];

    public static function sniff(?string $text): ?string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $text, $m) !== 1) {
                continue;
            }
            $code = strtoupper(trim($m[1], '-'));
            if (in_array(strtolower($code), self::NOT_CODES, true)) {
                continue;
            }
            // A real code has a digit or is all caps in the source — plain
            // lowercase prose ("code for members") is not one.
            if (preg_match('/[0-9]/', $code) !== 1 && $m[1] !== strtoupper($m[1])) {
                continue;
            }

            return $code;
        }

        return null;
    }
}
