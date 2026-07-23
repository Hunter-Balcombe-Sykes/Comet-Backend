<?php

namespace App\Services\WebsiteScan;

/**
 * Extracts a single accent hex colour from an already-fetched homepage +
 * (optional) favicon bytes — theme-color meta tag first, favicon dominant-
 * colour second, agreeing/disagreeing sources reconciled by RGB distance.
 * Mirrors the deleted EvidenceConclusions::qualifiesAsAccent()'s exact
 * quality gate (saturation >= 0.3, luminance strictly between 0.08 and 0.92)
 * so the "reject near-white/near-black/monochrome" behaviour already tuned
 * for this codebase isn't lost.
 */
class WebsiteAccentExtractor
{
    private const MIN_SATURATION = 0.3;

    private const MIN_LUMINANCE = 0.08;

    private const MAX_LUMINANCE = 0.92;

    private const AGREE_DIST = 60.0;

    public function extract(string $html, ?string $faviconBytes): ?string
    {
        $themeColor = $this->themeColorFromHtml($html);
        $faviconColor = $faviconBytes !== null ? $this->dominantColorFromImage($faviconBytes) : null;

        if ($themeColor !== null && $faviconColor !== null) {
            return $this->colorDistance($themeColor, $faviconColor) <= self::AGREE_DIST ? $themeColor : $faviconColor;
        }

        return $faviconColor ?? $themeColor;
    }

    private function themeColorFromHtml(string $html): ?string
    {
        if (! preg_match('/<meta[^>]+name=["\']theme-color["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
            return null;
        }
        $hex = $this->normalizeHex($m[1]);

        return $hex !== null && $this->qualifies($hex) ? $hex : null;
    }

    private function dominantColorFromImage(string $bytes): ?string
    {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $stepX = max(1, (int) ($width / 32));
        $stepY = max(1, (int) ($height / 32));
        $buckets = [];

        for ($y = 0; $y < $height; $y += $stepY) {
            for ($x = 0; $x < $width; $x += $stepX) {
                $rgb = imagecolorat($image, $x, $y);
                $alpha = ($rgb >> 24) & 0x7F;
                if ($alpha > 100) {
                    continue;
                }
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $hex = sprintf('#%02x%02x%02x', $r, $g, $b);
                if (! $this->qualifies($hex)) {
                    continue;
                }
                $bucketKey = sprintf('%02x%02x%02x', $r & 0xF0, $g & 0xF0, $b & 0xF0);
                $buckets[$bucketKey] = ($buckets[$bucketKey] ?? 0) + 1;
            }
        }

        if ($buckets === []) {
            return null;
        }

        arsort($buckets);

        return '#'.array_key_first($buckets);
    }

    private function qualifies(string $hex): bool
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

    private function normalizeHex(string $value): ?string
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

    private function colorDistance(string $hexA, string $hexB): float
    {
        sscanf($hexA, '#%02x%02x%02x', $r1, $g1, $b1);
        sscanf($hexB, '#%02x%02x%02x', $r2, $g2, $b2);

        return sqrt((($r1 - $r2) ** 2) + (($g1 - $g2) ** 2) + (($b1 - $b2) ** 2));
    }
}
