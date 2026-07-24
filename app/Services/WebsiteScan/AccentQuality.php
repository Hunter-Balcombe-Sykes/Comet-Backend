<?php

namespace App\Services\WebsiteScan;

/**
 * Shared accent-colour quality gate — extracted verbatim from
 * WebsiteAccentExtractor so every accent candidate source (theme-color,
 * favicon, logo palette, gallery palette) applies the exact same "reject
 * near-white/near-black/monochrome" rule (saturation >= 0.3, luminance
 * strictly between 0.08 and 0.92). Mirrors the deleted
 * EvidenceConclusions::qualifiesAsAccent()'s tuning.
 */
class AccentQuality
{
    private const MIN_SATURATION = 0.3;

    private const MIN_LUMINANCE = 0.08;

    private const MAX_LUMINANCE = 0.92;

    public static function qualifies(string $hex): bool
    {
        sscanf($hex, '#%02x%02x%02x', $r, $g, $b);
        $max = max($r, $g, $b) / 255;
        $min = min($r, $g, $b) / 255;
        $luminance = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
        // $max === 0.0 (strict) used to miss a pure-black pixel: max($r,$g,$b)/255
        // is an INTEGER 0 (not float 0.0) when the division is exact, so the old
        // strict-type guard fell through into a real division by zero. `> 0.0`
        // compares by value regardless of int/float, so it can't be fooled the
        // same way.
        $saturation = $max > 0.0 ? ($max - $min) / $max : 0.0;

        return $saturation >= self::MIN_SATURATION
            && $luminance > self::MIN_LUMINANCE
            && $luminance < self::MAX_LUMINANCE;
    }

    public static function normalizeHex(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^#?([0-9a-f]{6})$/i', $value, $m)) {
            return '#'.strtolower($m[1]);
        }
        if (preg_match('/^#?([0-9a-f]{3})$/i', $value, $m)) {
            return '#'.strtolower($m[1][0].$m[1][0].$m[1][1].$m[1][1].$m[1][2].$m[1][2]);
        }

        return null;
    }
}
