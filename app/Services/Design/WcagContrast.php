<?php

namespace App\Services\Design;

/**
 * Real WCAG 2.x contrast math (plan §13) — not the single-colour
 * AccentQuality gate. AccentQuality answers "is this colour AN accent at
 * all?" (saturation/luminance bands); this answers "can this exact pair be
 * read?", which needs relative luminance with sRGB linearisation and the
 * (L1+0.05)/(L2+0.05) ratio, nothing less.
 */
final class WcagContrast
{
    /** WCAG AA for normal text — the §13 bar for a brand accent. */
    public const AA_NORMAL = 4.5;

    /** Contrast ratio between two #rrggbb colours: 1.0 (none) … 21.0 (black/white). */
    public static function ratio(string $hexA, string $hexB): float
    {
        $la = self::relativeLuminance($hexA);
        $lb = self::relativeLuminance($hexB);

        [$lighter, $darker] = $la >= $lb ? [$la, $lb] : [$lb, $la];

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    public static function meetsAa(string $foreground, string $background): bool
    {
        return self::ratio($foreground, $background) >= self::AA_NORMAL;
    }

    /**
     * Tone-map a colour toward legibility on the given background: walk its
     * lightness away from the background (darker on light, lighter on dark)
     * until AA passes, keeping the hue — this is what makes a brand accent a
     * usable UI colour rather than a rejected one. Null when even the far end
     * of the ramp cannot pass (a mid-grey on a mid-grey has nowhere to go).
     */
    public static function toneToAa(string $hex, string $background): ?string
    {
        if (self::meetsAa($hex, $background)) {
            return $hex;
        }

        $towardDark = self::relativeLuminance($background) >= 0.5;
        [$h, $s, $l] = self::hexToHsl($hex);

        // 24 steps of ~3% lightness — fine enough that the result stays
        // recognisably the brand's colour instead of jumping to black/white.
        for ($i = 1; $i <= 24; $i++) {
            $l = $towardDark ? max(0.0, $l - 0.03) : min(1.0, $l + 0.03);
            $candidate = self::hslToHex($h, $s, $l);
            if (self::meetsAa($candidate, $background)) {
                return $candidate;
            }
        }

        return null;
    }

    /** WCAG relative luminance of an #rrggbb colour (sRGB linearised). */
    public static function relativeLuminance(string $hex): float
    {
        [$r, $g, $b] = self::rgb($hex);

        $lin = fn (int $channel): float => ($c = $channel / 255) <= 0.04045
            ? $c / 12.92
            : (($c + 0.055) / 1.055) ** 2.4;

        return 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /** @return array{0: float, 1: float, 2: float} h∈[0,360), s,l∈[0,1] */
    private static function hexToHsl(string $hex): array
    {
        [$r, $g, $b] = self::rgb($hex);
        $r /= 255;
        $g /= 255;
        $b /= 255;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;

        if ($max === $min) {
            return [0.0, 0.0, $l];
        }

        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

        $h = match ($max) {
            $r => fmod(($g - $b) / $d + ($g < $b ? 6 : 0), 6),
            $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        } * 60;

        return [$h, $s, $l];
    }

    private static function hslToHex(float $h, float $s, float $l): string
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;

        [$r, $g, $b] = match (true) {
            $h < 60 => [$c, $x, 0],
            $h < 120 => [$x, $c, 0],
            $h < 180 => [0, $c, $x],
            $h < 240 => [0, $x, $c],
            $h < 300 => [$x, 0, $c],
            default => [$c, 0, $x],
        };

        return sprintf(
            '#%02x%02x%02x',
            (int) round(($r + $m) * 255),
            (int) round(($g + $m) * 255),
            (int) round(($b + $m) * 255),
        );
    }
}
