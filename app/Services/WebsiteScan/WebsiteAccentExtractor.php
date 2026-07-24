<?php

namespace App\Services\WebsiteScan;

/**
 * Extracts a single accent hex colour from an already-fetched homepage +
 * (optional) favicon bytes — theme-color meta tag first, favicon dominant-
 * colour second, agreeing/disagreeing sources reconciled by RGB distance.
 * Quality gate (reject near-white/near-black/monochrome) is the shared
 * AccentQuality — see that class for the tuning rationale.
 */
class WebsiteAccentExtractor
{
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
        $hex = AccentQuality::normalizeHex($m[1]);

        return $hex !== null && AccentQuality::qualifies($hex) ? $hex : null;
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
                if (! AccentQuality::qualifies($hex)) {
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

    private function colorDistance(string $hexA, string $hexB): float
    {
        sscanf($hexA, '#%02x%02x%02x', $r1, $g1, $b1);
        sscanf($hexB, '#%02x%02x%02x', $r2, $g2, $b2);

        return sqrt((($r1 - $r2) ** 2) + (($g1 - $g2) ** 2) + (($b1 - $b2) ** 2));
    }
}
